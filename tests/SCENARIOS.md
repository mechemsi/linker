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

## 16. Edge Case: Whitespace-Only Parameter Values

**Scenario:** All parameters are provided but contain only whitespace characters (spaces, tabs, newlines).

**Input:**
- Workflow: `complete`
- Parameters: `{ server: "   ", status: "\t", message: " \n " }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true` (whitespace strings satisfy "required")
- `resolvedParameters` matches the whitespace input exactly
- Interpolation preserves whitespace characters verbatim

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesWhitespaceOnlyParameterValues`

---

## 17. Edge Case: Very Long Parameter Values

**Scenario:** Parameters contain extremely long strings (10,000+ characters).

**Input:**
- Workflow with a single required parameter
- Parameter value: 10,000 character string

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Full string is preserved in `resolvedParameters` and interpolated output
- No truncation or corruption

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesVeryLongParameterValues`

---

## 18. Edge Case: Unicode/Multibyte Characters in Values

**Scenario:** Parameter values contain CJK characters, Cyrillic script, and other multibyte Unicode.

**Input:**
- `{ server: "サーバー-01", status: "критический", message: "磁盘使用率: 99% /var/log 已満" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- `resolvedParameters` matches input exactly (no encoding issues)
- Interpolation preserves all multibyte characters
- Composite interpolation correctly combines unicode segments

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesUnicodeMultibyteParameterValues`
- Functional: `WorkflowExecutorCest::defaultWorkflowHandlesUnicodeParameters`

---

## 19. Edge Case: Null-Like String Values ("null", "undefined", "false")

**Scenario:** Parameter values are strings that look like programming null/false values.

**Input:**
- `{ server: "null", status: "undefined", message: "false" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Values are treated as literal strings, not converted to actual null/false
- Interpolation uses literal string values

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesNullLikeStringParameterValues`

---

## 20. Edge Case: Extra Parameters Not Defined in Workflow

**Scenario:** Input includes parameters that are not defined in the workflow schema.

**Input:**
- Workflow: `complete` (defines server, status, message)
- Parameters: `{ server: "web-01", status: "ok", message: "test", extra_param: "ignored", another: "also ignored" }`

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Extra parameters are silently ignored
- `resolvedParameters` contains only the defined parameters (3, not 5)

**Test locations:**
- Unit: `WorkflowExecutorTest::itIgnoresExtraParametersNotDefinedInWorkflow`
- Functional: `WorkflowExecutorCest::defaultWorkflowIgnoresExtraParameters`

---

## 21. Edge Case: Optional Parameter Without Default, Not Provided

**Scenario:** An optional parameter has no default value and is not provided in input.

**Input:**
- Workflow with required param + optional param (no default)
- Only required param provided

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Optional param is excluded from `resolvedParameters`
- Unresolved `{placeholder}` remains literally in interpolated step output

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesOptionalParameterWithNoDefaultNotProvided`

---

## 22. Edge Case: Step Templates with Unreferenced Placeholders

**Scenario:** Step parameter templates reference placeholders that don't correspond to any workflow parameter.

**Input:**
- Workflow defines param `name`; step template uses `{name}`, `{role}`, `{server}`
- Only `name` is a defined parameter

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Known placeholder `{name}` is resolved
- Unknown placeholders `{role}` and `{server}` remain literally in output

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesStepWithUnreferencedPlaceholders`

---

## 23. Edge Case: Multiple Missing Required Parameters Lists All

**Scenario:** Four required parameters exist, none provided.

**Acceptance Criteria:**
- `WorkflowResult.success` is `false`
- `WorkflowResult.error` mentions all four missing parameter names
- No steps executed

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesMultipleMissingRequiredParametersListingAll`
- Functional: `WorkflowExecutorCest::defaultWorkflowErrorListsAllMissingRequiredParams`

---

## 24. Edge Case: Various Exception Types from Steps

**Scenario:** Steps throw different exception types (LogicException, OverflowException) not just RuntimeException.

**Acceptance Criteria:**
- All exception types are caught (Throwable catch)
- Error messages from each type are captured in StepResult
- Execution continues past each failure

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesExceptionTypesOtherThanRuntimeException`

---

## 25. Edge Case: Step Returns Empty Transports List

**Scenario:** A step's `send()` call succeeds but returns an empty array of transports.

**Acceptance Criteria:**
- `StepResult.success` is `true` (no exception was thrown)
- `StepResult.notifiedTransports` is `[]`
- Overall workflow `success` is `true`

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesStepReturningEmptyTransportsList`

---

## 26. Edge Case: Newlines and Control Characters in Values

**Scenario:** Parameter values contain newline characters (\n, \r\n) and tabs.

**Acceptance Criteria:**
- `WorkflowResult.success` is `true`
- Control characters preserved verbatim in resolved parameters and interpolated output

**Test locations:**
- Unit: `WorkflowExecutorTest::itHandlesNewlinesInParameterValues`

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
