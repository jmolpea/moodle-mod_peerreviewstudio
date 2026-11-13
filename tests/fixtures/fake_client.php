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
 * AI client for tests: answers from memory instead of calling an AI provider.
 *
 * Everything else (the prompt, the AI policy, parsing the answer) is the
 * real client.
 *
 * @package     mod_peerreviewstudio
 * @category    test
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_client extends client {
    /** @var bool Whether an AI provider is available. */
    public bool $available = true;

    /** @var string|null Text the provider answers with; null makes every request fail. */
    public ?string $answer = null;

    /** @var int Error code of a failed request. */
    public int $errorcode = 500;

    /** @var \stdClass[] Every request made: prompt, contextid, userid. */
    public array $requests = [];

    /**
     * Whether an AI provider is available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->available;
    }

    /**
     * Answer with a grade, or fail.
     *
     * @param float|null $grade Grade to answer with; null makes every request fail.
     * @param int $errorcode Error code of the failure.
     * @return void
     */
    public function answer_with(?float $grade, int $errorcode = 500): void {
        $this->answer = $grade === null ? null : json_encode(['grade' => $grade, 'feedback' => 'AI feedback']);
        $this->errorcode = $errorcode;
    }

    /**
     * Record the request and return the canned answer.
     *
     * @param string $prompt Full prompt.
     * @param int $contextid Context id.
     * @param int $userid User id.
     * @return string|null
     */
    protected function generate(string $prompt, int $contextid, int $userid): ?string {
        $this->requests[] = (object) ['prompt' => $prompt, 'contextid' => $contextid, 'userid' => $userid];
        if ($this->answer === null) {
            $this->lasterrorcode = $this->errorcode;
            $this->lasterror = 'The fake provider failed with error ' . $this->errorcode;
            return null;
        }

        return $this->answer;
    }
}
