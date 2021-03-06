Feature:
  In order to have insights on the ongoing platform
  As a decider
  I need to have access to some metrics

  Scenario: A tide failed
    Given a tide is started with a build task
    When the tide failed
    Then an event should be sent to keen in the collection "tides"

  Scenario: A tide succeed
    Given a tide is started with a build task
    When the tide is successful
    Then an event should be sent to keen in the collection "tides"

  Scenario: A build task succeed
    Given there is 1 application images in the repository
    And a tide is started with a build task
    When the build succeed
    Then an event should be sent to keen in the collection "builds"

  Scenario: A build task failed
    Given there is 1 application images in the repository
    And a tide is started with a build task
    And the build is failing
    Then an event should be sent to keen in the collection "builds"

  Scenario: A deploy task succeed
    Given a tide is started with a deploy task
    When the deployment succeed
    Then an event should be sent to keen in the collection "deployments"

  Scenario: A deploy task failed
    Given a tide is started with a deploy task
    When the deployment failed
    Then an event should be sent to keen in the collection "deployments"
