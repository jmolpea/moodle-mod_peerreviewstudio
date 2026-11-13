@mod @mod_peerreviewstudio
Feature: Learners submit work, review each other, and receive anonymous feedback
  In order to learn from my peers
  As a student
  I need to submit my work, review the work assigned to me, and read the feedback I receive

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | student1 | Sam       | One      | student1@example.com |
      | student2 | Sara      | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity         | course | name       | idnumber | workflowmode | submissiontype | reviewcountrequired | reviewsreceivedtarget |
      | peerreviewstudio | C1     | Peer essay | prs1     | open         | onlinetext     | 1                   | 1                     |

  Scenario: Two learners submit, review each other, and read their feedback
    Given I am on the "Peer essay" "peerreviewstudio activity" page logged in as student1
    Then I should see "Submit your work."
    When I set the field "Your submission" to "Essay by Sam about tides"
    And I press "Submit my work"
    Then I should see "Your work has been submitted."
    And I should see "Waiting for reviewers"
    And I should see "Your feedback and grade will appear here once your submission has received all its reviews."
    # The second learner is matched with the first one as soon as they submit.
    When I am on the "Peer essay" "peerreviewstudio activity" page logged in as student2
    And I set the field "Your submission" to "Essay by Sara about volcanoes"
    And I press "Submit my work"
    Then I should see "Review the peer work assigned to you."
    And I should see "Essay by Sam about tides"
    And I should see "Your submission can no longer be edited because it has been matched with reviewers."
    And I should not see "Sam One"
    # A review needs a level for every criterion.
    When I set the field "Overall comment" to "Clear and convincing"
    And I press "Submit review"
    Then I should see "Select a level for this criterion."
    When I set the field with xpath "(//select[starts-with(@name, 'criterion_')])[1]" to "Very strong (4.00)"
    And I set the field with xpath "(//select[starts-with(@name, 'criterion_')])[2]" to "Very strong (4.00)"
    And I set the field with xpath "(//select[starts-with(@name, 'criterion_')])[3]" to "Good (3.00)"
    And I press "Submit review"
    Then I should see "Your review has been submitted."
    And I should see "Reviews completed: 1 / 1"
    # The author reads the feedback without learning who wrote it.
    When I am on the "Peer essay" "peerreviewstudio activity" page logged in as student1
    Then I should see "Reviews complete"
    And I should see "Feedback from peer reviewer 1"
    And I should see "Clear and convincing"
    And I should see "91.67 / 100.00"
    And I should not see "Sara Two"

  Scenario: A teacher follows the activity and sets a final grade
    Given I am on the "Peer essay" "peerreviewstudio activity" page logged in as student1
    And I set the field "Your submission" to "Essay by Sam about tides"
    And I press "Submit my work"
    When I am on the "Peer essay" "peerreviewstudio activity" page logged in as teacher1
    Then I should see "Tutor dashboard"
    And I should see "Sam One"
    And I should see "Waiting for reviewers"
    And I should not see "Your submission"
    When I follow "Sam One"
    Then I should see "Submission by Sam One"
    And I should see "Essay by Sam about tides"
    And I should see "No reviewer has been matched to this submission yet."
    When I set the field "Final grade override" to "not a number"
    And I press "Save grade"
    Then I should see "The grade must be a number."
    When I set the field "Final grade override" to "85"
    And I press "Save grade"
    Then I should see "The grade has been updated."
    And I should see "Set by the teacher"
    # A learner cannot open the tutor page.
    When I am on the "Peer essay" "peerreviewstudio activity" page logged in as student2
    Then I should not see "Tutor dashboard"
