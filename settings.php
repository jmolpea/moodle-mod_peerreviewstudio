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

/**
 * Admin settings for mod_peerreviewstudio (AI review provider).
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_peerreviewstudio/aiheading',
        get_string('setting_aiheading', 'mod_peerreviewstudio'),
        get_string('setting_aiheading_desc', 'mod_peerreviewstudio')
    ));

    $settings->add(new admin_setting_configselect(
        'mod_peerreviewstudio/aiprovider',
        get_string('setting_aiprovider', 'mod_peerreviewstudio'),
        get_string('setting_aiprovider_desc', 'mod_peerreviewstudio'),
        '',
        [
            '' => get_string('setting_aiprovider_none', 'mod_peerreviewstudio'),
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Google Gemini',
            'deepseek' => 'DeepSeek',
        ]
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_peerreviewstudio/aiapikey',
        get_string('setting_aiapikey', 'mod_peerreviewstudio'),
        get_string('setting_aiapikey_desc', 'mod_peerreviewstudio'),
        ''
    ));

    // One model list per provider: only the list for the selected provider
    // is used, so switching providers keeps each provider's model choice.
    $settings->add(new admin_setting_configselect(
        'mod_peerreviewstudio/aimodel_openai',
        get_string('setting_aimodel', 'mod_peerreviewstudio', 'OpenAI'),
        get_string('setting_aimodel_desc', 'mod_peerreviewstudio'),
        'gpt-4o-mini',
        [
            'gpt-4o-mini' => 'gpt-4o-mini',
            'gpt-4o' => 'gpt-4o',
            'gpt-4.1-mini' => 'gpt-4.1-mini',
            'gpt-4.1' => 'gpt-4.1',
            'gpt-5.1' => 'gpt-5.1',
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_peerreviewstudio/aimodel_anthropic',
        get_string('setting_aimodel', 'mod_peerreviewstudio', 'Anthropic'),
        get_string('setting_aimodel_desc', 'mod_peerreviewstudio'),
        'claude-haiku-4-5-20251001',
        [
            'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
            'claude-sonnet-5' => 'Claude Sonnet 5',
            'claude-opus-4-8' => 'Claude Opus 4.8',
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_peerreviewstudio/aimodel_gemini',
        get_string('setting_aimodel', 'mod_peerreviewstudio', 'Google Gemini'),
        get_string('setting_aimodel_desc', 'mod_peerreviewstudio'),
        'gemini-2.5-flash',
        [
            'gemini-2.5-flash' => 'gemini-2.5-flash',
            'gemini-2.5-pro' => 'gemini-2.5-pro',
            'gemini-2.0-flash' => 'gemini-2.0-flash',
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_peerreviewstudio/aimodel_deepseek',
        get_string('setting_aimodel', 'mod_peerreviewstudio', 'DeepSeek'),
        get_string('setting_aimodel_desc', 'mod_peerreviewstudio'),
        'deepseek-chat',
        [
            'deepseek-chat' => 'deepseek-chat',
            'deepseek-reasoner' => 'deepseek-reasoner',
        ]
    ));
}
