@mod @mod_peerreviewstudio
Feature: AI review only uses the work of learners who accept the site AI policy
  In order to decide whether an AI may read my work
  As a student
  I need to be told that AI review is enabled and to accept the site AI policy myself

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Tina      | Teacher  | teacher1@example.com |
      | student1 | Sam       | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A learner is told about AI review and accepts the AI policy in the activity
    Given an AI provider that generates text is available for Peer Review Studio
    And the following "activities" exist:
      | activity         | course | name     | idnumber | workflowmode | submissiontype | aireviewenabled |
      | peerreviewstudio | C1     | AI essay | prs1     | open         | onlinetext     | 1               |
    When I am on the "AI essay" "peerreviewstudio activity" page logged in as student1
    Then I should see "AI review is enabled in this activity"
    And I should see "Your work is only sent to the AI once you have accepted the AI usage policy of this site."
    When I press "Accept and continue"
    Then I should see "You have accepted the AI policy."
    And I should see "AI review is enabled in this activity"
    And I should not see "Your work is only sent to the AI once you have accepted the AI usage policy of this site."
    # The learner can still submit as usual.
    When I set the field "Your submission" to "Essay by Sam about tides"
    And I press "Submit my work"
    Then I should see "Your work has been submitted."
    # Tutors are not shown the learner notice.
    When I am on the "AI essay" "peerreviewstudio activity" page logged in as teacher1
    Then I should see "Tutor dashboard"
    And I should not see "AI review is enabled in this activity"

  Scenario: Without an AI provider the activity does not mention AI
    Given the following "activities" exist:
      | activity         | course | name     | idnumber | workflowmode | submissiontype | aireviewenabled |
      | peerreviewstudio | C1     | AI essay | prs1     | open         | onlinetext     | 1               |
    When I am on the "AI essay" "peerreviewstudio activity" page logged in as student1
    Then I should see "Submit your work."
    And I should not see "AI review is enabled in this activity"
