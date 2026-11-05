# Requirements Document

## Introduction

The HRIS application has two separately-styled areas: the HR workspace (built on the shared "Moonlit" design system in `assets/css/hris-base.css` with a PHP template at `templates/hrTemplates/`) and the Employee portal. A recent change converted most employee pages to the shared design system and introduced a shared employee template (`templates/employeeTemplates/`), but left gaps: the Leave Management pages were only partially re-themed, two navigation destinations (Payslips, Settings) were removed because the pages never existed, and a set of login/dashboard presentation issues remain.

This spec covers bringing the entire employee portal to full visual and structural consistency with the HR workspace, building the two missing pages (Payslips, Settings), restoring navigation, and resolving the outstanding login form and dashboard-reports presentation issues.

### Technical context (confirmed against the codebase)
- This is a **server-rendered PHP / XAMPP multi-page application**, not a SPA. There is no JS router or component framework. "Routes" are individual `.php` files; "the shared layout" is a PHP `header.php` / `footer.php` include pair; "global CSS" is `assets/css/hris-base.css` plus per-page files in `assets/css/pages/`.
- The shared employee shell already exists at `templates/employeeTemplates/header.php` and `footer.php`.
- The Leave Management pages (`employee/file_leave.php`, `employee/leave_history.php`) currently keep their original Tailwind-CDN sidebar/topbar markup and were only palette-retuned; they do not yet inherit the shared shell.
- `employee/payslips.php` and `employee/settings.php` **do not exist** in the repository. The database schema has **no payslip or settings tables**. A `payroll` role and a "Net Pay" placeholder exist, but no payroll data model.
- The login page (`authentication/login.php`) already contains a single toggle button with two SVGs (eye / eye-off), a lock icon (`.user-icon2`), and an error container (`.field-error`); its styling lives in `asset/css/style.css`. Any duplicate-icon / missing-lock / misaligned-error symptoms originate in CSS or a stale asset, not the markup structure.

## Requirements

### Requirement 1: Leave Management pages inherit the master layout

**User Story:** As an employee, I want the Leave Management and Leave History pages to look identical in theme to the rest of the portal, so that the experience feels like one consistent application.

#### Acceptance Criteria
1. WHEN an employee opens `file_leave.php` or `leave_history.php` THEN the page SHALL render using the shared employee shell (`templates/employeeTemplates/header.php` + `footer.php`) with the same fixed sidebar, topbar, and background as the dashboard.
2. WHEN these pages render THEN they SHALL load `assets/css/hris-base.css` and a dedicated per-page stylesheet in `assets/css/pages/`, and SHALL NOT rely on the Tailwind CDN for layout structure.
3. WHEN these pages render THEN the sidebar navigation, logo mark, logout button, and user footer SHALL be visually and structurally identical to the other employee pages.
4. WHEN an employee uses the leave request form, the 24-hour cooldown countdown, the custom "Other" leave-type field, the day-count preview, the history table, and the slide-over history panel THEN all existing behavior SHALL continue to function exactly as before the restyle.
5. WHEN the leave request form is submitted with valid or invalid data THEN all existing validation, cooldown enforcement, audit logging, and database writes SHALL behave identically to the current implementation.

### Requirement 2: Complete and consistent navigation

**User Story:** As an employee, I want every section of the portal reachable from the sidebar, so that I can navigate without dead ends or missing links.

#### Acceptance Criteria
1. WHEN any employee page renders THEN the sidebar SHALL present links to Dashboard, My Profile, Attendance, Leave, Payslips, and Settings.
2. WHEN an employee is on a given page THEN the corresponding sidebar item SHALL be marked active, and leave-related pages SHALL share a single active "Leave" state.
3. WHEN the navigation is defined THEN it SHALL be defined once in the shared template so that all pages stay in sync.
4. WHERE a navigation link points to a page THEN that page SHALL exist and load without a 404 or fatal error.
5. WHEN the sidebar renders on HR pages THEN its existing HR link set SHALL remain unchanged (this spec does not alter HR navigation).

### Requirement 3: Payslips page

**User Story:** As an employee, I want a Payslips page, so that I can view my pay information within the portal.

#### Acceptance Criteria
1. WHEN an authenticated employee opens `payslips.php` THEN the page SHALL load using the shared employee shell without errors.
2. IF no payroll data model exists for the employee THEN the page SHALL display a clear, well-styled empty state consistent with the design system rather than an error or blank screen.
3. WHEN the page renders THEN it SHALL show the current pay period and a net-pay summary area consistent with the dashboard's payslip card.
4. WHEN an unauthenticated user or a non-employee role requests the page THEN the page SHALL redirect to login or return an access-denied response, matching the guard pattern used by the other employee pages.
5. WHERE payroll source data is later added THEN the page structure SHALL accommodate listing historical payslips without a redesign.

### Requirement 4: Settings page

**User Story:** As an employee, I want a Settings page, so that I can view and update my account preferences and information.

#### Acceptance Criteria
1. WHEN an authenticated employee opens `settings.php` THEN the page SHALL load using the shared employee shell without errors.
2. WHEN the page renders THEN it SHALL display the employee's editable account information using fields backed by existing columns (e.g. phone, email) and SHALL clearly indicate any read-only fields.
3. WHEN the employee submits a change to an editable field with valid input THEN the change SHALL be persisted to the database and a success confirmation SHALL be shown.
4. WHEN the employee submits invalid input THEN a validation message SHALL be shown and no invalid data SHALL be written.
5. WHEN an unauthenticated user or a non-employee role requests the page THEN the page SHALL redirect to login or return an access-denied response, matching the guard pattern used by the other employee pages.
6. WHEN updates are written THEN they SHALL use parameterized queries and escape output, consistent with the rest of the codebase.

### Requirement 5: Login form presentation fixes

**User Story:** As a user signing in, I want a clean, correctly laid out login form, so that the password controls and error messages are clear and unambiguous.

#### Acceptance Criteria
1. WHEN the password field is displayed THEN exactly one password-visibility toggle icon SHALL be visible at a time (eye when hidden, eye-off when shown), with no duplicate or overlapping icons.
2. WHEN the password field is displayed THEN the lock icon SHALL be visible and correctly positioned without being overlapped by the toggle control.
3. WHEN the visibility toggle is clicked THEN the password field SHALL switch between masked and visible, and the icon SHALL reflect the current state.
4. WHEN a login error occurs THEN the error message SHALL appear directly beneath the input area within its designated alert container, correctly aligned with the form.
5. WHEN these fixes are applied THEN all existing login authentication, session handling, and role-based redirect behavior SHALL remain unchanged.

### Requirement 6: Reports embedded in the dashboard

**User Story:** As a user with access to reports, I want reports shown within the dashboard view, so that I do not have to navigate to a separate standalone page.

#### Acceptance Criteria
1. WHEN a user with reports access opens the relevant dashboard THEN the reports content SHALL be rendered inline within the dashboard view rather than requiring navigation to a standalone page.
2. WHEN reports are embedded THEN the existing report data queries and output SHALL be reused without duplicating logic.
3. WHEN the dashboard renders the embedded reports THEN the layout SHALL remain consistent with the design system and SHALL not break the existing dashboard widgets.
4. WHERE a standalone report page is still reachable THEN it SHALL continue to function, OR be intentionally retired as part of the design decision recorded in the design phase.
5. WHEN embedding is complete THEN access control for reports SHALL be preserved (only authorized roles see the reports section).

### Requirement 7: No regressions to existing behavior

**User Story:** As a stakeholder, I want these presentation and feature changes to avoid breaking working functionality, so that the application remains stable.

#### Acceptance Criteria
1. WHEN any page in scope is changed THEN its existing authentication guards, session handling, and database logic SHALL remain functionally unchanged.
2. WHEN pages are restyled THEN all form IDs, field names, and JavaScript hooks required by existing scripts SHALL be preserved or correctly updated so interactive behavior keeps working.
3. WHEN the work is complete THEN every changed PHP file SHALL pass a syntax lint, and each in-scope page SHALL execute without a parse or fatal error up to the point of its data dependencies.
4. WHEN temporary files or test artifacts are created during the work THEN they SHALL be removed before completion.

## Glossary

- **Shared employee shell**: The PHP include pair `templates/employeeTemplates/header.php` and `footer.php` that renders the sidebar, topbar, background, and closing scripts for every employee page.
- **Design system / Moonlit theme**: The shared visual language defined in `assets/css/hris-base.css` (deep-navy background, teal→blue accent gradient, violet logo mark, shared card/pill/table styles).
- **Per-page stylesheet**: A CSS file under `assets/css/pages/` that supplements the design system with styles specific to one page.
- **HR workspace**: The HR-facing pages under `hr/`, using `templates/hrTemplates/`. Out of scope for changes except where Requirement 6 (reports) touches an HR dashboard.
- **Employee portal**: The employee-facing pages under `employee/`.
- **Auth guard**: The standard block at the top of each employee page that verifies the session and the `employee` role before rendering.
- **Legacy UI**: The older Tailwind-CDN sidebar/topbar markup that predates the shared employee shell.
