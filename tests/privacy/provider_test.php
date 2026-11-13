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

namespace mod_peerreviewstudio\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Unit tests for the privacy provider.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_peerreviewstudio\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass Activity instance (with cmid). */
    protected \stdClass $instance;

    /** @var \context_module Module context. */
    protected \context_module $context;

    /** @var \stdClass Author of the reviewed submission. */
    protected \stdClass $author;

    /** @var \stdClass Reviewer who completed a review of the author's work. */
    protected \stdClass $reviewer;

    /** @var \stdClass The author's submission. */
    protected \stdClass $submission;

    /**
     * Two learners who submitted; the reviewer has reviewed the author and
     * the author still owes the reviewer a review.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_peerreviewstudio');
        $course = $generator->create_course();
        $this->instance = $plugingenerator->create_instance(['course' => $course->id]);
        $this->context = \context_module::instance($this->instance->cmid);
        $this->author = $generator->create_and_enrol($course, 'student');
        $this->reviewer = $generator->create_and_enrol($course, 'student');

        $this->submission = $plugingenerator->create_submission($this->instance, $this->author->id, 'Author essay');
        $plugingenerator->create_submission($this->instance, $this->reviewer->id, 'Reviewer essay');
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_peerreviewstudio',
            'filearea' => 'submission_files',
            'itemid' => $this->submission->id,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], 'Essay file');
        $plugingenerator->create_review(
            $this->instance,
            $plugingenerator->get_open_assignment((int)$this->submission->id, (int)$this->reviewer->id),
            3,
            'Reviewer general comment'
        );
    }

    public function test_metadata_describes_tables_subsystems_and_the_ai_provider(): void {
        $collection = provider::get_metadata(new collection('mod_peerreviewstudio'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());

        foreach (
            [
            'peerreviewstudio_submissions', 'peerreviewstudio_assignments', 'peerreviewstudio_reviews',
            'peerreviewstudio_r_answers', 'core_files', 'core_message', 'core_ai',
            ] as $expected
        ) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_contexts_and_users_are_found_for_authors_and_reviewers(): void {
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid((int)$this->author->id)->get_contextids());
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid((int)$this->reviewer->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid((int)$stranger->id)->get_contextids());

        $userlist = new userlist($this->context, 'mod_peerreviewstudio');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->author->id, $this->reviewer->id], $userlist->get_userids());
    }

    public function test_export_gives_authors_their_feedback_without_the_reviewer_identity(): void {
        $this->export_context_data_for_user((int)$this->author->id, $this->context, 'mod_peerreviewstudio');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([]);
        $this->assertCount(1, $data->submissions);
        $this->assertStringContainsString('Author essay', $data->submissions[0]->onlinetext);
        $feedback = $data->submissions[0]->feedbackreceived;
        $this->assertCount(1, $feedback);
        $this->assertSame('Reviewer general comment', $feedback[0]->generalcomment);
        $this->assertCount(3, $feedback[0]->rubricanswers);
        $this->assertStringNotContainsString((string)$this->reviewer->id, json_encode($feedback));
        $this->assertFalse(property_exists($feedback[0], 'reviewerid'));
        $this->assertNotEmpty($writer->get_files([]));

        // The author's own reviewing work: one review still pending.
        $this->assertCount(1, $data->reviewsassigned);
        $this->assertSame('assigned', $data->reviewsassigned[0]->status);
        $this->assertNull($data->reviewsassigned[0]->review);
    }

    public function test_export_gives_reviewers_the_reviews_they_wrote(): void {
        $this->export_context_data_for_user((int)$this->reviewer->id, $this->context, 'mod_peerreviewstudio');
        $data = writer::with_context($this->context)->get_data([]);

        $this->assertCount(1, $data->reviewsassigned);
        $this->assertSame('completed', $data->reviewsassigned[0]->status);
        $this->assertSame('Reviewer general comment', $data->reviewsassigned[0]->review->generalcomment);
        // The reviewer's own submission has not been reviewed yet.
        $this->assertSame([], $data->submissions[0]->feedbackreceived);
    }

    public function test_deleting_an_author_erases_the_work_and_frees_the_reviewers(): void {
        global $DB;

        $contextlist = new approved_contextlist($this->author, 'mod_peerreviewstudio', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $this->submission->id]);
        $this->assertSame(0, (int)$submission->userid);
        $this->assertSame('', $submission->onlinetext);
        $this->assertNull($submission->finalgrade);
        $this->assertSame(
            [],
            get_file_storage()->get_area_files($this->context->id, 'mod_peerreviewstudio', 'submission_files', $submission->id)
        );
        $this->assertSame([], provider::get_contexts_for_userid((int)$this->author->id)->get_contextids());

        // The review the author owed is withdrawn and the reviewer's work waits for a new reviewer.
        $this->assertSame(0, $DB->count_records('peerreviewstudio_assignments', ['status' => 'assigned']));
        $this->assertSame(
            'queued',
            $DB->get_field('peerreviewstudio_submissions', 'status', ['userid' => $this->reviewer->id])
        );

        // The reviewer keeps their own data.
        $this->assertSame(
            'Reviewer general comment',
            $DB->get_field('peerreviewstudio_reviews', 'generalcomment', ['reviewerid' => $this->reviewer->id])
        );
    }

    public function test_deleting_a_reviewer_strips_identity_and_text_but_keeps_the_grade(): void {
        global $DB;

        $userlist = new approved_userlist($this->context, 'mod_peerreviewstudio', [$this->reviewer->id]);
        provider::delete_data_for_users($userlist);

        $review = $DB->get_record('peerreviewstudio_reviews', ['submissionid' => $this->submission->id]);
        $this->assertSame(0, (int)$review->reviewerid);
        $this->assertSame('', $review->generalcomment);
        $this->assertEqualsWithDelta(100.0, (float)$review->grade, 0.0001);
        $this->assertSame(0, $DB->count_records_select('peerreviewstudio_r_answers', "commenttext <> ''"));
        $this->assertSame(0, $DB->count_records('peerreviewstudio_assignments', ['reviewerid' => $this->reviewer->id]));

        // The author's grade is unaffected.
        $this->assertEqualsWithDelta(
            100.0,
            (float)$DB->get_field('peerreviewstudio_submissions', 'finalgrade', ['id' => $this->submission->id]),
            0.0001
        );
    }

    public function test_deleting_a_context_removes_every_learner_row_and_file(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        foreach (
            [
            'peerreviewstudio_submissions', 'peerreviewstudio_assignments', 'peerreviewstudio_reviews',
            'peerreviewstudio_r_answers', 'peerreviewstudio_queue',
            ] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertSame(
            [],
            get_file_storage()->get_area_files($this->context->id, 'mod_peerreviewstudio', 'submission_files')
        );
        $this->assertSame(1, $DB->count_records('peerreviewstudio_rubrics'));
    }
}
