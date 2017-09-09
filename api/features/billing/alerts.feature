Feature:
  In order to be aware of the problems related to a team
  As a user
  I want to be alerted if there is any problem

  Background:
    Given I am authenticated as user "samuel"
    And there is a team "foo"
    And the user "samuel" is in the team "foo"
    And there is a billing profile "00000000-0000-0000-0000-000000000000" for the user "samuel"

  Scenario: It alerts when the team do not have any billing profile
    When I request the alerts of the team "foo"
    Then I should see the "billing-profile-not-found" alert
