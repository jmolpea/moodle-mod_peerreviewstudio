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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @category    test
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_peerreviewstudio extends behat_base {
    /**
     * Set up an AI provider that offers text generation, as a site administrator would.
     *
     * The provider has a fake key: scenarios that use this step must not
     * trigger an AI request.
     *
     * @Given an AI provider that generates text is available for Peer Review Studio
     */
    public function an_ai_provider_that_generates_text_is_available_for_peer_review_studio(): void {
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        $manager = \core\di::get(\core_ai\manager::class);

        // From Moodle 5.0 providers are instances.
        if (method_exists($manager, 'create_provider_instance')) {
            $manager->create_provider_instance(
                classname: '\aiprovider_openai\provider',
                name: 'Behat provider',
                enabled: true,
                config: ['apikey' => 'behat-key'],
                actionconfig: [
                    \core_ai\aiactions\generate_text::class => [
                        'enabled' => true,
                        'settings' => ['model' => 'gpt-behat', 'endpoint' => $endpoint, 'systeminstruction' => ''],
                    ],
                ],
            );
            return;
        }

        // Moodle 4.5: one provider per plugin, configured with plugin settings.
        set_config('apikey', 'behat-key', 'aiprovider_openai');
        set_config('action_generate_text_model', 'gpt-behat', 'aiprovider_openai');
        set_config('action_generate_text_endpoint', $endpoint, 'aiprovider_openai');
        \core\plugininfo\aiprovider::enable_plugin('openai', 1);
    }
}
