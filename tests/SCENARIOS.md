# Workflow Test Scenarios & Acceptance Criteria

Defines the key scenarios for validating workflow template execution, including happy paths, edge cases, and expected output formats.

---

## 1. Happy Path: Single-Step Workflow Execution

**Scenario:** Execute a workflow with one step and all required parameters provided.

**Input:**
- Workflow: `single-step` (1 required parameter, 1 step)
- Parameters: `{ message: "Deployment completed successfully" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `WorkflowResult.error` is `null`
- `WorkflowResult.workflowName` matches the requested workflow name
- `WorkflowResult.resolvedParameters` matches the input exactly
- `WorkflowResult.stepResults` contains exactly 1 `StepResult`
- `StepResult.success` is `true`
- `StepResult.stepName` and `StepResult.linkName` match the workflow definition
- `StepResult.notifiedTransports` is non-empty
- `StepResult.error` is `null`

**Test locations:**
- Unit: `WorkflowExecutorTest::itExecutesSingleStepWorkflowSuccessfully`

---

## 2. Happy Path: Multi-Step Workflow Execution

**Scenario:** Execute a workflow with multiple steps, each targeting different links.

**Input:**
- Workflow: `multi-step` (4 parameters, 3 steps)
- Parameters: `{ app: "linker-api", version: "2.4.0", environment: "production", deployer: "ci-bot" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `WorkflowResult.stepResults` contains exactly 3 entries
- Each step has the correct `stepName` and `linkName` per the workflow definition
- Steps execute in declaration order: `notify-team`, `alert-ops`, `log-deploy`
- All steps report `success: true`
- Each step's interpolated parameters match expected resolved values:
  - Step 1: `{ message: "Deploy started: linker-api v2.4.0 to production by ci-bot" }`
  - Step 2: `{ server: "production", status: "deploying", message: "linker-api v2.4.0" }`
  - Step 3: `{ app: "linker-api", version: "2.4.0", environment: "production" }`

**Test locations:**
- Unit: `WorkflowExecutorTest::itExecutesMultiStepWorkflowSuccessfully`
- Unit: `WorkflowExecutorTest::itExecutesMultiStepFixtureWorkflow`
- Unit: `WorkflowExecutorTest::itValidatesMultiStepWorkflowOutputsMatchExpectedResults`
- Functional: `WorkflowExecutorCest::defaultWorkflowExecutesAndCapturesResults`

---

## 3. Happy Path: Optional Parameters Resolved with Defaults

**Scenario:** Execute a workflow where optional parameters are omitted, relying on configured defaults.

**Input:**
- Workflow: `complete` (2 required + 1 optional with default `"No details provided"`)
- Parameters: `{ server: "db-02.staging", status: "warning" }` (no `message`)

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `resolvedParameters` includes the default: `{ server: "db-02.staging", status: "warning", message: "No details provided" }`
- Interpolated step parameters use the default value (e.g., log message contains `"No details provided"`)

**Test locations:**
- Unit: `WorkflowExecutorTest::itResolvesOptionalParametersWithDefaults`
- Unit: `WorkflowExecutorTest::itExecutesCompleteWorkflowWithDefaultParameters`
- Functional: `WorkflowExecutorCest::defaultWorkflowAppliesDefaultForOptionalMessage`
- Functional: `WorkflowExecutorCest::defaultWorkflowWithDefaultMessageProducesExpectedOutput`

---

## 4. Happy Path: All-Optional Parameters with No Input

**Scenario:** Execute a workflow where every parameter is optional, providing zero input.

**Input:**
- Workflow: `all-optional-params` (3 optional parameters with defaults)
- Parameters: `{}` (empty)

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `resolvedParameters` contains all defaults: `{ title: "Default Title", body: "Default Body", priority: "low" }`
- Interpolated step output: `{ message: "[low] Default Title: Default Body" }`

**Test locations:**
- Unit: `WorkflowExecutorTest::itValidatesAllOptionalParamsWorkflowWithNoInputUsesAllDefaults`

---

## 5. Happy Path: Workflow with No Steps

**Scenario:** Execute a workflow that defines no steps (minimal configuration).

**Input:**
- Workflow: `minimal` (0 parameters, 0 steps)
- Parameters: `{}`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `WorkflowResult.stepResults` is an empty array
- `LinkNotificationService::send` is never called

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesWorkflowWithNoSteps`

---

## 6. Error: Missing Required Parameters

**Scenario:** Execute a workflow without supplying one or more required parameters.

**Input:**
- Workflow: `complete` (requires `server` and `status`)
- Parameters: `{}` (empty)

**Acceptance Criteria:**
- `WorkflowResult.success` is `false`
- `WorkflowResult.error` is non-null and contains the names of all missing required parameters
- `WorkflowResult.stepResults` is an empty array (no steps executed)
- `WorkflowResult.resolvedParameters` is an empty array
- `LinkNotificationService::send` is never called

**Test locations:**
- Unit: `WorkflowExecutorTest::itFailsWithMissingRequiredParameters`
- Functional: `WorkflowExecutorCest::defaultWorkflowFailsWithMissingRequiredParameters`
- Functional: `WorkflowExecutorCest::defaultWorkflowFailsPartiallyWhenRequiredParamMissing`

---

## 7. Error: Workflow Not Found

**Scenario:** Request execution of a workflow name that does not exist.

**Input:**
- Workflow: `"nonexistent"`
- Parameters: `{}`

**Acceptance Criteria:**
- A `WorkflowNotFoundException` is thrown
- Exception message contains the requested workflow name

**Test locations:**
- Unit: `WorkflowExecutorTest::itPropagatesWorkflowNotFoundException`

---

## 8. Partial Failure: Step Fails but Remaining Steps Continue

**Scenario:** A workflow with multiple steps where one step throws an exception during execution.

**Input:**
- Workflow with 3 steps where step 2 (`link-b`) throws `RuntimeException("Connection timeout")`

**Acceptance Criteria:**
- `WorkflowResult.success` is `false` (any step failure marks overall as failed)
- `WorkflowResult.error` is `null` (no parameter validation error)
- All 3 steps are present in `stepResults` (execution was not aborted)
- Step 1: `success: true`, `notifiedTransports: ["slack"]`, `error: null`
- Step 2: `success: false`, `notifiedTransports: []`, `error: "Connection timeout"`
- Step 3: `success: true`, `notifiedTransports: ["email", "telegram"]`, `error: null`

**Test locations:**
- Unit: `WorkflowExecutorTest::itCapturesStepFailureWithoutAbortingRemainingSteps`
- Unit: `WorkflowExecutorTest::itValidatesPartialFailureOutputContainsCorrectStepResults`

---

## 9. Total Failure: All Steps Fail

**Scenario:** Every step in a workflow throws an exception.

**Input:**
- Workflow with 2 steps, both links throw `RuntimeException`

**Acceptance Criteria:**
- `WorkflowResult.success` is `false`
- `WorkflowResult.error` is `null` (parameters were valid)
- Every `StepResult.success` is `false`
- Every `StepResult.notifiedTransports` is `[]`
- Every `StepResult.error` is non-null and contains the specific error message

**Test locations:**
- Unit: `WorkflowExecutorTest::itValidatesAllStepsFailedProducesCorrectOutput`

---

## 10. Edge Case: Empty String Parameter Values

**Scenario:** All parameters are provided but as empty strings.

**Input:**
- Workflow: `complete`
- Parameters: `{ server: "", status: "", message: "" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true` (empty strings satisfy "required" — the parameter is present)
- `resolvedParameters` matches the empty-string input
- Interpolation produces empty segments: step 1 params are `{ server: "", status: "", message: "" }`
- Composite interpolation reflects empty values: `{ message: "[] Server : " }`

**Test locations:**
- Unit: `WorkflowExecutorTest::itValidatesEmptyStringInputsProduceExpectedOutputs`

---

## 11. Edge Case: Special Characters in Values

**Scenario:** Parameter values contain HTML entities, ampersands, em-dashes, percent signs, and angle brackets.

**Input:**
- `{ server: "web-01.prod (primary)", status: "error & critical", message: "Disk usage: 99% — /var/log full <alert>" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `resolvedParameters` matches input exactly (no encoding or escaping)
- Interpolated output preserves all special characters verbatim

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesSpecialCharactersInParameterValues`

---

## 12. Edge Case: Brace-Like Characters in Values

**Scenario:** Parameter values contain literal `{braces}` that resemble placeholder syntax.

**Input:**
- `{ server: "host-{unknown}", status: "{status}", message: "Literal {braces} in message" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `resolvedParameters` matches input exactly
- Literal braces in values are preserved — they are NOT re-interpolated
- Step 1 params: `{ server: "host-{unknown}", status: "{status}", message: "Literal {braces} in message" }`
- Composite output: `{ message: "[{status}] Server host-{unknown}: Literal {braces} in message" }`

**Test locations:**
- Unit: `WorkflowExecutorTest::itValidatesBracesInValuesDoNotCauseUnexpectedInterpolation`

---

## 13. Integration: DI Container Wiring

**Scenario:** Verify that `WorkflowExecutor` and `WorkflowConfigLoader` are properly wired in the Symfony service container.

**Acceptance Criteria:**
- `WorkflowExecutor` is retrievable from the DI container
- `WorkflowConfigLoader` is retrievable from the DI container
- Both services are correctly instantiated (not null, correct type)

**Test locations:**
- Functional: `WorkflowExecutorCest::workflowExecutorServiceIsAvailable`
- Functional: `WorkflowConfigLoaderCest::defaultWorkflowIsLoadedFromConfig`

---

## 14. Integration: Default Workflow Loads from Config Directory

**Scenario:** The `default.yaml` workflow in `config/workflows/` is loadable via the DI-injected `WorkflowConfigLoader`.

**Acceptance Criteria:**
- Workflow `name` is `"default"`
- Workflow `description` is `"Default notification workflow"`
- Workflow has 3 parameters: `server` (required), `status` (required), `message` (optional)
- Workflow has 2 steps: `alert` -> `server-alert`, `log` -> `test-slack`
- Step link references correspond to existing link configs

**Test locations:**
- Functional: `WorkflowExecutorCest::defaultWorkflowCanBeLoadedAndHasSteps`
- Functional: `WorkflowConfigLoaderCest::defaultWorkflowHasExpectedParameters`
- Functional: `WorkflowConfigLoaderCest::defaultWorkflowStepsReferenceValidLinks`

---

## 15. Integration: Step Results Have Consistent Shape

**Scenario:** After execution, every `StepResult` has a well-formed structure regardless of success/failure.

**Acceptance Criteria:**
- Every `StepResult` has a non-empty `stepName`
- Every `StepResult` has a non-empty `linkName`
- Successful steps: `error` is `null`, `notifiedTransports` is non-empty
- Failed steps: `error` is non-null (contains message)

**Test locations:**
- Functional: `WorkflowExecutorCest::defaultWorkflowStepResultsHaveNoUnexpectedErrors`

---

## Output Format Reference

A passing `WorkflowResult` (success) has this shape:

```php
WorkflowResult {
    workflowName: string,       // Matches requested workflow
    success: true,
    resolvedParameters: [       // Input params + applied defaults
        'param' => 'value',
    ],
    stepResults: [
        StepResult {
            stepName: string,           // From workflow definition
            linkName: string,           // From workflow definition
            success: true,
            notifiedTransports: [...],  // Non-empty list of transport names
            error: null,
        },
    ],
    error: null,
}
```

A failing `WorkflowResult` (parameter validation) has this shape:

```php
WorkflowResult {
    workflowName: string,
    success: false,
    resolvedParameters: [],     // Empty — validation failed before resolution
    stepResults: [],            // Empty — no steps executed
    error: string,              // Contains names of missing required params
}
```

A partially failing `WorkflowResult` (step failure) has this shape:

```php
WorkflowResult {
    workflowName: string,
    success: false,             // Any step failure → overall false
    resolvedParameters: [...],  // Fully resolved — params were valid
    stepResults: [
        StepResult { success: true, ... },
        StepResult { success: false, error: "...", notifiedTransports: [] },
        StepResult { success: true, ... },  // Continues after failure
    ],
    error: null,                // No param validation error
}
```
