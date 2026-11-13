<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_peerreviewstudio\local\ai;

/**
 * Turns a learner submission into plain text an AI model can assess.
 *
 * The online text, plain-text files, and word processor documents (.docx,
 * .odt) are read. Anything else (PDF, images, archives...) cannot be read,
 * and a submission is only reported as readable when all of it could be:
 * the AI must never grade work it has only partly seen.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_reader {
    /**
     * Largest submission, in characters, that is sent to the AI in full.
     */
    const MAX_CHARS = 120000;

    /**
     * Largest file, in bytes, that is opened.
     */
    const MAX_FILE_BYTES = 20971520;

    /**
     * Largest document body, in bytes, read from inside a .docx or .odt file.
     */
    const MAX_XML_BYTES = 8388608;

    /**
     * File extensions read as plain text.
     */
    const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'tex', 'rtf',
        'html', 'htm', 'css', 'js', 'ts', 'py', 'java', 'c', 'h', 'cpp', 'cs', 'php', 'rb', 'go', 'rs', 'sql', 'r', 'sh',
    ];

    /**
     * Read one submission.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $submission Submission record.
     * @return array{text: string, readable: bool, unreadable: string[]} The text that could be read,
     *         whether that is the whole submission, and the names of the files that could not be read.
     */
    public static function read(\context_module $context, \stdClass $submission): array {
        $parts = [];
        $unreadable = [];

        $onlinetext = self::html_to_plain((string)$submission->onlinetext);
        if ($onlinetext !== '') {
            $parts[] = $onlinetext;
        }

        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_peerreviewstudio',
            'submission_files',
            (int)$submission->id,
            'filename',
            false
        );
        foreach ($files as $file) {
            $text = self::read_file($file);
            if ($text === null) {
                $unreadable[] = $file->get_filename();
                continue;
            }
            if (trim($text) !== '') {
                $parts[] = 'FILE "' . $file->get_filename() . "\":\n" . trim($text);
            }
        }

        $text = implode("\n\n", $parts);
        $toolong = \core_text::strlen($text) > self::MAX_CHARS;

        return [
            'text' => $text,
            'readable' => $text !== '' && !$unreadable && !$toolong,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * Convert HTML to plain text, keeping the words exactly as written.
     *
     * @param string $html HTML content.
     * @return string
     */
    public static function html_to_plain(string $html): string {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        $text = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Extract the text of one stored file.
     *
     * @param \stored_file $file Stored file.
     * @return string|null Null when the file type cannot be read.
     */
    protected static function read_file(\stored_file $file): ?string {
        if ($file->get_filesize() > self::MAX_FILE_BYTES) {
            return null;
        }

        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        if ($extension === 'docx') {
            return self::read_zipped_xml($file, 'word/document.xml', ['</w:p>', '<w:br/>', '<w:br />'], ['<w:tab/>']);
        }
        if ($extension === 'odt') {
            return self::read_zipped_xml(
                $file,
                'content.xml',
                ['</text:p>', '</text:h>', '<text:line-break/>'],
                ['<text:tab/>']
            );
        }

        if (in_array($extension, self::TEXT_EXTENSIONS, true) || strpos((string)$file->get_mimetype(), 'text/') === 0) {
            $content = (string)$file->get_content();
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = \core_text::convert($content, 'ISO-8859-1', 'UTF-8');
            }
            if (in_array($extension, ['html', 'htm'], true)) {
                $content = self::html_to_plain($content);
            }
            return $content;
        }

        return null;
    }

    /**
     * Read the body of a zipped XML document (.docx, .odt) as plain text.
     *
     * @param \stored_file $file Stored file.
     * @param string $entry Path of the document body inside the archive.
     * @param string[] $linebreaks Markup that ends a line.
     * @param string[] $tabs Markup that stands for a tab.
     * @return string|null Null when the document cannot be opened.
     */
    protected static function read_zipped_xml(\stored_file $file, string $entry, array $linebreaks, array $tabs): ?string {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $path = $file->copy_content_to_temp();
        if (!$path) {
            return null;
        }

        $xml = null;
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $stat = $zip->statName($entry);
            // The declared size guards against decompression bombs.
            if ($stat !== false && (int)$stat['size'] <= self::MAX_XML_BYTES) {
                $xml = $zip->getFromName($entry);
            }
            $zip->close();
        }
        if (file_exists($path)) {
            unlink($path);
        }

        if ($xml === null || $xml === false) {
            return null;
        }

        $text = str_replace($linebreaks, "\n", $xml);
        $text = str_replace($tabs, "\t", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
