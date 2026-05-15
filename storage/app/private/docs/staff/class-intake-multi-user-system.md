# Class Intake Multi User System

## Index

- [Purpose Of This Note](#purpose-of-this-note)
- [Current Implementation Snapshot](#current-implementation-snapshot)
- [Current Recovered Context](#current-recovered-context)
- [Frontend Traces Already Present](#frontend-traces-already-present)
- [Backend Traces Already Present](#backend-traces-already-present)
- [Working Interpretation](#working-interpretation)
- [Unified Three-Screen Experience](#unified-three-screen-experience)
- [Roles And Privileges](#roles-and-privileges)
- [Staff And Trainer Scope](#staff-and-trainer-scope)
- [Student Population And Intake Creation](#student-population-and-intake-creation)
- [Multi User Meaning](#multi-user-meaning)
- [Fake Accounts And Intentional Game Vulnerability](#fake-accounts-and-intentional-game-vulnerability)
- [Avatar Tampering And Realtime Profile Updates](#avatar-tampering-and-realtime-profile-updates)
- [On Air / Elimination Logic](#on-air--elimination-logic)
- [Student Login Direction](#student-login-direction)
- [Documentation In The Dashboard](#documentation-in-the-dashboard)
- [IP Address Monitoring](#ip-address-monitoring)
- [Staff And Student Permissions](#staff-and-student-permissions)
- [Open Capture Areas](#open-capture-areas)
- [Update Log](#update-log)

## Purpose Of This Note

This document is a rolling reconstruction of the Class Intake Multi User System after the unexpected Windows reboot on 2026-05-13 caused loss of volatile working memory.

As more detail is supplied, this file should be updated so the recovered design, current implementation state, open questions, and intended next steps are preserved in one place.

## Current Implementation Snapshot

Last updated: 2026-05-15.

Current working direction:

- User Management is the main operating surface for the Class Intake Multi User System.
- Staff users live in `users`; Students and fake game accounts live in `game_users`.
- Student users are always scoped to their own Class Intake.
- Staff users with Admin powers can see the Class Intake selector in User Management.
- Students never see the Class Intake selector.
- Protectors and Trainers are intended to see Students only from Class Intakes linked to them.
- If a Protector or Trainer has no linked Class Intake, User Management shows an info-gradient warning and no Students.
- Staff-to-intake linking is many-to-many through `staff_intake_assignments`.
- The old `game_intakes.trainer_user_id` field is treated as a legacy fallback, not the final assignment model.

Current frontend implementation points:

- `src/cruds/user-management/index.js` controls User Management intake visibility, linked-intake warnings, timeout feedback, and Staff/Admin selector visibility.
- `src/components/GameIntakeSelector/index.js` can now render an API-provided intake list.
- `src/cruds/class-intake-management/index.js` has an initial Staff Assignments pane for linking Staff users to the selected intake.
- `src/cruds/documentation/MarkdownRenderer.jsx` renders Markdown docs and now supports same-page index links without opening a new tab.
- `src/services/cruds-service.js` sends `vmd_user_email` with User Management requests so Laravel can enforce viewer-specific intake access.

Current backend implementation points:

- `database/migrations/2026_05_15_000000_create_staff_intake_assignments_table.php` creates the many-to-many Staff/intake assignment table.
- `routes/api.php` exposes:
  - `VMD-get-staff-game-intakes`
  - `VMD-get-class-intake-management-data`
  - `VMD-save-staff-intake-assignments`
- `CustomController.php` loads visible Staff intakes, loads Class Intake Management assignment data, and saves Staff-intake links.
- `UserController.php` receives `vmd_user_email` and prevents non-authorized viewers from pulling Students for unrelated intakes.

Verified recently:

- Laravel syntax checks passed for `CustomController.php`, `UserController.php`, `routes/api.php`, and the new migration.
- The Dashboard Documentation section can render the Class Intake System document.
- The Markdown index displays and internal index links now stay in the current tab.
- User Management warning wording now says `linked to a Class Intake`, so it fits both Trainers and Protectors.

Still open:

- Run `php artisan migrate` anywhere the new `staff_intake_assignments` table does not yet exist.
- Run a frontend build before deployment after JavaScript changes.
- Test Class Intake Management assignment save/load end-to-end with real Staff users.
- Test a linked Protector/Trainer account sees only linked-intake Students.
- Test an unlinked Protector/Trainer account sees the info-gradient warning and no Students.
- Apply the same linked-intake access model to Audit History and Login History.
- Decide the exact future roles and powers for `Super Admin` and `Trainer`.
- There will only ever be one Super Admin; `Admin` remains the in-game prize for Students.

## Current Recovered Context

The project is split across two separate codebases:

- React frontend: `D:\Documents\SourceTree Repos\reactjs-dashboard`
- Laravel backend: `C:\xampp\htdocs\laravel-json-api-pro`

The existing project notes in `/docs` describe the wider dashboard deployment, SSE/session work, and other recently reconstructed behavior. The backend does not currently have its own `/docs` folder, so backend context is being recovered from its `README.md`, route listing, models, migrations, and controllers.

Historical architecture context:

- The dashboard began as a purchased Creative Tim codebase from Romania.
- The React repository served as the frontend.
- The Laravel repository served as the backend.
- The original package was heavily modified over time to fit this project's needs.
- As the system evolved, classic CRUD/API scaffolding became less central than the specialized business logic accumulated in `CustomController`.
- Future analysis should therefore treat the custom endpoints and controller flows as the primary application behavior, with generic CRUD routes often being secondary.

## Frontend Traces Already Present

The React application already contains visible Class Intake work:

- `src/components/GameIntakeSelector/index.js`
  - Presents the current class intake context.
  - Lets the operator choose an intake.
- `src/utils/gameIntakeContext.js`
  - Holds known intake definitions.
  - Persists the selected intake in local storage.
  - Broadcasts selection changes across the app.
- `src/cruds/class-intake-management/index.js`
  - Provides a Class Intake Management screen.
  - Appears to be a prototype workflow for creating intakes and managing game users.
- `src/cruds/user-management/index.js`
  - Filters users by selected intake.
  - Applies intake-specific permissions for student/game-user actions.
- `src/cruds/user-login-history/VMD_Recentlogins.jsx`
  - Filters login history by selected intake.
- `src/cruds/user-audit-history/VMD_UserAuditHistory.jsx`
  - Filters audit history by selected intake.
- `src/auth/login/VMD_Modal_Login.jsx`
  - Includes intake data in returned login/profile state.
  - Mentions students using their class intake email.

The frontend route and sidenav definitions already expose:

- `Class Intake Management`
- Route: `/examples-api/class-intake-management`

## Backend Traces Already Present

The Laravel application already contains data and logic for intake-aware users:

- Models:
  - `app/Models/GameIntake.php`
  - `app/Models/GameUser.php`
  - `app/Models/GameBaseline.php`
  - Intake relationships on `app/Models/User.php`
- Controllers:
  - `app/Http/Controllers/CustomController.php`
  - `app/Http/Controllers/UserController.php`
- Database migrations:
  - `2026_05_10_000000_create_game_intake_tables.php`
  - `2026_05_10_000002_create_game_users_table.php`
  - `2026_05_10_000003_refine_game_user_schema_and_user_baselines.php`
  - `2026_05_10_000005_seed_game_users_24_16.php`
  - `2026_05_10_000006_seed_game_users_24_17.php`

Recovered behavior visible from code references:

- Separate intake records via `game_intakes`.
- Intake-specific student/game-user records via `game_users`.
- Active-week and intake naming data returned into login/profile flows.
- Backend filtering by `game_intake_id` and `game_intake_code`.
- Intake-aware login, ban, delete, update, audit-history, and login-history paths.
- Dashboard settings include an active intake concept and baseline-reset-related settings.

## Working Interpretation

The system supports multiple student class intakes playing the dashboard's internal game at the same time.

The game concept is:

- Students are allowed to hack the system.
- The system is meant to become harder to hack each week.
- The original teaching pattern came from students finding security holes and those holes being patched week by week.
- Each intake can progress through the game independently.
- Game progress is organized by intake week, currently understood as Week One through Week Six.
- The winner for a week is the last player still "on air."

The original design pushed all game activity through the `users` table. That created the need for backup tables in phpMyAdmin because staff records and game-participant records were being mixed in the same primary table.

The intended split is now:

- Equinim staff users live in the main `users` table.
- Students/game participants live in the `game_users` table.
- Students are associated with class intake identifiers.
- The dashboard should still present the management experience as though staff and the selected intake's students belong to one coherent user list.

This "single table" effect is a UI and query-composition goal, not a literal database-table goal.

## Unified Three-Screen Experience

The three main management screens should respond to the selected class intake:

- User Management
- Login History
- Audit History

When a staff user changes the selected intake, those three screens should refresh their visible data so they appear to be operating over one combined staff-plus-intake population.

The intended illusion is:

- Staff rows come from `users`.
- Student/game-user rows come from `game_users` for the selected intake.
- The UI should make that composition feel like one data table or one unified operating context.

Intake scoping rule:

- Staff users use the currently selected Class Intake context.
- Student/game users must never inherit the browser's general selected intake context.
- Student/game users must always be scoped to their own `game_intake_id` and `game_intake_code` from the logged-in identity record.
- This rule applies to User Management, Audit History, and Login History.
- A fresh browser session may have no selected intake stored at all, so relying on the default active intake would incorrectly expose a different class to a Student.
- The stable cross-environment identifier is `game_intake_code`. Numeric intake IDs can differ between localhost and production databases after migrations/seeding, so backend filters must prefer `game_intake_code` when both code and ID are supplied.

The current User Management screen is already considered fairly mature and should not be reworked just for the sake of redefining this blend. The existing presentation is the intended baseline:

- selected-intake students appear in the same management table as staff users
- the list naturally crosses over from student rows into staff rows
- the current columns, controls, and overall visual treatment are broadly acceptable as-is

User Management filter direction:

- The primary quick filters should support the game model while preserving the complete merged view.
- Button one / default: `All Users`.
- Button two: `Staff Users`.
- Button three: `Student Users`.
- Button four: `Online Users`.
- `Staff Users` and `Student Users` make the split between `users` and `game_users` visible to players.
- `Online Users` remains important because it supports elimination and "last player on air" gameplay.

UI placement correction:

- The Class Intake selector component has been returned to the top of User Management, where it matches the intake-scoped operating workflow.
- It has been removed from My Profile.
- It must be visible only to Staff users.
- It must never be visible to Students/game users.
- The selector should eventually include an input/control for selecting a known Staff User as the dedicated Trainer for that class intake.

Edit User profile presentation:

- When Staff inspect or edit a Student/game-user profile, the Billing Address pane is hidden.
- The Billing Address sidenav item is hidden at the same time.
- Staff user edit profiles retain the Billing Address pane.
- This keeps Student profile administration focused on game-relevant account data rather than unused customer/accounting fields.

## Roles And Privileges

There are five main roles:

- Admin
- Creator
- Member
- Protector
- Spy

The Spy role is still a work in progress and should be treated as unfinished design/implementation.

## Staff And Trainer Scope

The system is intended to support many staff members, especially trainers, participating in the game alongside students and fake accounts at the same time.

Longer-term access control requirement:

- Senior Equinim management may need broad visibility across intakes.
- Trainers should eventually be restricted from seeing students from other class intakes.
- The expected real-world rule is that a trainer teaches one class intake at a time.
- Trainer-to-intake assignment should be controlled by Equinim Management users with the appropriate Admin-level dashboard access.

This implies the selected-intake context is not merely a convenience filter; eventually it also becomes part of authorization and visibility policy.

Important operational nuance:

- A class intake lasts about 44 weeks including holidays.
- It is common for at least two trainers to teach the same intake across that lifespan.
- Because of that, the eventual trainer assignment model must handle trainer handover over time, even if the active visibility rule is intended to feel one-intake-at-a-time from the trainer's perspective.

Current Staff intake visibility direction:

- Only Staff users with Admin powers should see the Class Intake selector in User Management.
- Students must never see the Class Intake selector.
- Protectors and Trainers should not choose arbitrary intakes from User Management.
- Protectors and Trainers should see Students only from intakes linked to them by a Staff user with Admin powers.
- If a Protector or Trainer has no linked intake, User Management should show an info-gradient warning that they will not be able to see Students until an intake is linked in Class Intake Management.
- Staff users with Admin powers can still see all intakes because they need to allocate and manage the training structure.

Assignment model:

- The old `game_intakes.trainer_user_id` field is now only a legacy single-trainer shortcut.
- It is too narrow for real training delivery because one Trainer can teach more than one intake and one intake can have more than one Trainer during handover.
- The many-to-many assignment table is `staff_intake_assignments`.
- That table links `users.id` to `game_intakes.id` with:
  - `assignment_type`
  - `active`
  - optional `starts_at` / `ends_at`
  - optional `assigned_by_user_id`
  - optional `notes`
- User Management asks Laravel for the Staff member's visible intakes through `VMD-get-staff-game-intakes`.
- The merged `users` endpoint also receives `vmd_user_email`, so Laravel can reject unassigned intake requests instead of trusting the browser-selected intake.
- Class Intake Management uses `VMD-get-class-intake-management-data` to load real intakes, current active assignments, and Staff candidates.
- Staff users with Admin powers can save the selected intake's assigned Staff list through `VMD-save-staff-intake-assignments`.
- This same Staff-intake assignment model should eventually be applied consistently to Audit History and Login History.

## Student Population And Intake Creation

Current known state:

- Existing student/game-user data was populated from spreadsheets supplied around a year before the 2026 recovery work.
- The frontend UI already exists for:
  - adding class intakes manually
  - adding students manually
- The Laravel backend for intake creation/editing and student creation/editing had not yet been commenced at the time of this recovery note.

Current boundary of responsibility:

- Class Intake Management still needs backend support for:
  - create/edit class intakes
  - create/edit students
- Week advancement is already handled in Global Management.
- Game reset / baseline restore is already largely complete in Global Management.
- Winner detection is connected to the "on air" logic and therefore depends on how elimination/off-air status is ultimately finalized.

## Multi User Meaning

The "Multi User" part of the system title refers to concurrent gameplay and administration across:

- many students,
- many class intakes,
- many staff trainers,
- and fake accounts,

all operating in the same dashboard/game environment at the same time.

## Fake Accounts And Intentional Game Vulnerability

Fake accounts are part of the intended hacking gameplay.

Recovered design intent:

- Students may attempt to create fake accounts, including accounts that impersonate staff.
- A major intentionally preserved bug/vulnerability in the game is the Add User flow.
- That Add User weakness is deliberate because it allows players to create accounts with Admin privileges as part of the challenge.

Storage rule for student-created fake accounts:

- If a student creates a fake account, it should live in `game_users`.
- The fake account must be linked to the same class intake as the student who created it.

Hard isolation rule:

- Students from one class intake must never be able to see students from another class intake.
- Student-created fake accounts must also stay inside that same intake boundary.

## Avatar Tampering And Realtime Profile Updates

Changing another player's avatar is part of the expected game behavior when the acting player has sufficient role privileges.

Historical game context:

- One of the first hacks students discovered was changing a staff avatar without permission.
- That incident helped reveal that students were creating accounts with Admin privileges.
- Avatar changes therefore should not be treated purely as cosmetic account maintenance; they are also a live gameplay signal.

Implementation requirement:

- Staff avatars are stored on `users.profile_image`.
- Student and fake-account avatars are stored on `game_users.profile_image`.
- If a privileged player changes another user's avatar, the target user's currently logged-in dashboard should update in real time.
- Realtime profile refresh must preserve whether the logged-in identity is Staff or Student, because fake/impersonation accounts can make email-only lookup unsafe.

## On Air / Elimination Logic

The current working meaning of a player being "on air" is tied to whether they can still participate meaningfully and continue producing online-user heartbeats.

Known ways a player can be weakened or removed from effective play:

- Reduce their role to `Member`, which leaves them with little practical power.
- Ban them, which blocks continued account use.
- Delete them, which also prevents continued account use.

Ban and delete are currently the stronger elimination paths because the affected account can no longer produce heartbeats and therefore drops out of the online-user population.

This game rule may still be refined later, so it should be treated as the current operating interpretation rather than a final immutable ruleset.

Delete timeout rule:

- When enabled, a Student who bans or deletes an active fellow Student receives a User Management timeout.
- The timeout length is controlled by Global Management and defaults to 5 minutes.
- During timeout, User Management write attempts are blocked with a countdown message: `You are in timeout because you either banned or deleted a fellow user. Time remaining: ...`
- User Management should show an error-gradient countdown strip while the logged-in Student is in timeout.
- Blocked User Management write attempts should be stopped at the frontend before the API call, while the backend remains the hard enforcement layer.
- Blocked attempts should also give the acting Student immediate feedback through a toast and a persisted Notification.
- The timeout is stored on the acting Student's `game_users.action_locked_until` field and does not apply to Staff users.

## Student Login Direction

Long-term student login is expected to remain email-and-password based.

Reason:

- Email/password login allows real 2FA email workflows to be used.
- Google login is likely not intended for students.

Current prediction:

- Staff may continue to use Google login where appropriate.
- Students should not use Google login.

2FA game timing:

- 2FA is expected to become relevant later in the game, likely Week 5 or Week 6.
- The 2FA login functions should operate for both Staff logins and Student logins when enabled.
- Student 2FA must use the `game_users` identity source, not assume the pending login belongs to the `users` table.

## Documentation In The Dashboard

Dashboard documentation should be served as Markdown content from Laravel rather than bundled into the React app.

Production file location:

```text
/var/www/html/Laravel/laravel-json-api-pro/storage/app/private/docs/
```

Current intended structure:

```text
storage/app/private/docs/
  student/
    game-guide.md
  staff/
    system-notes.md
```

Access model:

- Student-facing docs live under `student`.
- Staff/internal docs live under `staff`.
- Laravel should read these files and return Markdown through a controlled API endpoint.
- React should render the returned Markdown inside the Dashboard Documentation section.
- Student-facing docs can be seen by Students and Staff.
- Staff/internal docs should be restricted to the appropriate Staff/Admin roles.

Initial frontend placement:

- Left sidebar section: `Documentation`.
- First page: `Game Guide`.
- Route: `/documentation/game-guide`.

## IP Address Monitoring

IP address monitoring is part of gameplay and learning, not just security logging.

Known requirement:

- A student may use more than one PC during a teaching session.
- A student may be logged in from two different public IPv4 addresses at the same time.
- The dashboard needs to keep tracking the incoming IPv4/IPv6 during the session, not only at login.

Current model:

- Login History records the IPv4/IPv6 observed at login time in `user_login_history`.
- User Presence / Online Users records the latest IPv4/IPv6 from heartbeat in `user_presence`.
- Audit History records the action-time IP address in `user_audit_history.created_by_ip_address`.

Important implementation rule:

- Frontend code should gather `vmd_ip_address_v4` and `vmd_ip_address_v6` using `utils/clientIpInfo.js`.
- Backend audit and presence writers should prefer a valid `vmd_ip_address_v4` over localhost/proxy fallback values.
- Login IP, heartbeat IP, and audit action IP are related but intentionally separate snapshots.

Recovered implementation detail:

- `src/utils/clientIpInfo.js` gathers public IP data client-side.
- It calls `https://v4.api.ipinfo.io/ip` for IPv4.
- It calls `https://v6.api.ipinfo.io/ip` for IPv6.
- It returns those values as:
  - `vmd_ip_address_v4`
  - `vmd_ip_address_v6`

Why the frontend gathers these values:

- In the local XAMPP / Apache / Laravel setup, backend request IPs often resolve to `127.0.0.1` or proxy/local network values.
- Those values are not useful for the classroom game.
- The browser-side IP lookup gives the public IPv4/IPv6 visible to the outside world.
- That public IPv4 is the value students can reason about when they are watching each other come online, move networks, or use multiple devices.

Login-time IP capture:

- Staff Google login and manual login send `vmd_ip_address_v4` and `vmd_ip_address_v6` to `/VMD-login-user`.
- Student email/password login sends the same values to `/VMD-login-user`.
- `CustomController::F0_VMD_login_user()` first resolves IPs from server/proxy headers, then prefers valid client-supplied `vmd_ip_address_v4` / `vmd_ip_address_v6`.
- Login records are written to `user_login_history`.
- `user_login_history` stores:
  - `ip_address`
  - `ip_address_v4`
  - `ip_address_v6`
  - geolocation fields
  - user agent
  - `login_identity_type`

2FA login nuance:

- When 2FA is required for a student, the login-time IP data cannot be written immediately as a completed login.
- The pending student login caches the resolved IP/geolocation/user-agent data.
- After successful 2FA verification, that cached data is written into `user_login_history`.

Live-session IP capture:

- Login History is only a point-in-time record.
- It cannot prove where the user is throughout a teaching session.
- Students may have multiple PCs, multiple browser sessions, or changing network paths.
- `UserHeartbeat` therefore sends current `vmd_ip_address_v4` and `vmd_ip_address_v6` on each heartbeat.
- `CustomController::F0_VMD_user_heartbeat()` stores the current values in `user_presence`.
- `user_presence.ip_address` remains the preferred display IP.
- `user_presence.ip_address_v4` and `user_presence.ip_address_v6` preserve the separated values.
- User Management's Online Users view can use this presence data as the current live IP view.

Audit-action IP capture:

- Audit History is an action trail, not a session trail.
- Each important action should record the IP address at the time the action was performed.
- This is why edit, ban, unban, delete, role-change, password, billing, basic-info, and avatar-related requests should include current `vmd_ip_address_v4` / `vmd_ip_address_v6`.
- `user_audit_history.created_by_ip_address` is currently a single display field, so the backend should prefer IPv4 when available.
- If separate audit IPv4/IPv6 columns are ever needed, they should be added by migration rather than overloading the existing display field.

Why this matters for the game:

- Seeing an account appear from two different public IPv4 addresses is evidence students can use while playing.
- IP changes can suggest device switching, home-network changes, VPN/proxy behavior, or deliberate misdirection.
- The game needs three different truths:
  - where the account logged in from
  - where the account appears to be right now
  - where a specific action was performed from
- Collapsing those into one field would make the game less useful and make the evidence trail misleading.

## Staff And Student Permissions

Important implementation lesson recovered on 2026-05-13:

- Staff users and student/game users can display the same role name, such as `Creator`.
- Staff role data comes from the `users` table.
- Student role data comes from `game_users.game_role`.
- The sidebar does not rely only on the visible role label; it is driven by the permissions returned from `VMD-get-user-permissions`.

Bug found:

- `VMD-get-user-permissions` originally looked only in `users`.
- A student/game user with `game_role = Creator` therefore displayed as `Creator` but received an empty permissions array.
- That made management links disappear from the sidebar even though the visible role label matched a staff Creator.

Fix direction:

- Permission lookup must resolve the effective role for both identity sources:
  - staff: `users.role_id` / `users.role_name`
  - students: `game_users.game_role`
- Once the effective role is resolved, both identity types should read permissions from the same `roles` and `role_has_permissions` tables.
- User Management's merged listing must also display each Student's own `game_users.game_role`.
- It must not stamp all `game_users` rows with a shared fallback role such as `Creator`.
- When building JSON:API-style role relationship data for Student rows, map each Student's `game_role` back to the matching `roles.id`.
- Game-user management checks must recognize both Staff Admin/Protector roles in `users` and Student Admin/Protector roles in `game_users`.
- Avatar upload endpoints must run the same permission checks before writing `game_users.profile_image`; otherwise an upload can succeed while the follow-up profile update correctly reports a permission failure.
- Student/game-user avatar changes should have one database-writing path. The upload endpoint should save `game_users.profile_image` and write the `Profile image updated` audit entry, instead of uploading first and then calling the basic-info update endpoint just to persist the same avatar.

Current UI action nuance:

- Sidebar visibility and page access are not the same thing as row-level action access.
- A student/game user with `Creator` permissions can now see Creator-level management screens.
- User Management row action icons are presently available only to Admin and Protector users.
- Therefore Creator may be able to reach User Management while still being unable to use edit/ban/delete style row actions.
- The Creator role can see what is happening, but should not be treated as a broad edit role.
- The key power of Creator is the ability to create new accounts, including accounts with Admin or Protector privileges, through the intentionally vulnerable Add User flow.

Future Spy role direction:

- Row action access is eventually intended to include Spy users.
- Spies should be able to impersonate other users, making them difficult to identify.
- Protectors are intended to be the counter-role that can see, ban, or delete Spies.
- Spies should still be able to attack/zap Protectors, so the relationship is adversarial rather than one-way.
- This Spy/Protector balance is game design and remains unfinished.

## Open Capture Areas

The following should be filled in as the design is retold:

- Student/game-user lifecycle.
- Staff workflow for creating, switching, closing, or archiving intakes.
- Exact login rules for class intake users.
- Relationship between active intake, baseline reset, and game state.
- Required APIs still missing or incomplete.
- Known bugs, half-finished work, and next implementation priorities.
- Future role discussion: whether Equinim needs a higher `Super Admin` role above Admin.
- Future Staff role discussion: add a Trainer role and decide whether it inherits Creator-like powers while receiving intake assignment limits.

## Update Log

- 2026-05-13: Created this recovery note and captured the implementation traces currently visible in both codebases.
- 2026-05-13: Added the recovered product intent for multi-intake gameplay, the `users` versus `game_users` split, the unified three-screen UI goal, the five role names, trainer visibility expectations, intake progression by week, and the current manual-intake/manual-student UI state.
- 2026-05-13: Added the weekly game-hardening concept: each week should become harder to hack, reflecting the original classroom pattern of students finding holes and the system being patched between rounds.
- 2026-05-14: Recorded that the Class Intake selector now belongs at the top of User Management, is Staff-only, and has been removed from My Profile.
- 2026-05-14: Recorded that Student/game-user edit profiles should hide Billing Address in both the pane layout and the edit-profile sidenav.
- 2026-05-14: Recorded the intake isolation bug found during a fresh Student WEBDEV-24-16 session: User Management fell back to the browser's default CYBER-24-14 intake. The rule is now that Staff use the selected intake context, while Students are pinned to their own intake across User Management, Audit History, and Login History.
- 2026-05-14: Recorded the User Management filter change: button one/default is `All Users`, button two is `Staff Users`, button three is `Student Users`, and button four is `Online Users`.
- 2026-05-14: Recorded the merged User Management role-display bug: Student permissions and realtime profile state used `game_users.game_role`, but the merged listing displayed every Student as `Creator`. The listing must display and relate each Student row using its own `game_role`.
- 2026-05-14: Recorded the production intake-filter bug where `game_intake_id=3` and `game_intake_code=EQ-CYBER-24-14` returned the wrong seeded student group because numeric intake IDs are not guaranteed to match between localhost and production. User Management, Login History, and Audit History should treat `game_intake_code` as authoritative and use numeric ID only as a fallback.
- 2026-05-14: Recorded the two-step Student avatar update bug: `uploads/game-users/{id}/profile-image` wrote the avatar before permission checks, while the follow-up `VMD-update-game-user-basic-info` rejected Student Admin actors because `canManageGameUsers()` only checked Staff users. Both paths now need to recognize Student Admin/Protector roles and reject unauthorized avatar writes before saving.
- 2026-05-14: Recorded the residual Student avatar update error: after permissions were fixed, the frontend still made a second basic-info call after the upload. The game-user avatar path should now rely on the upload endpoint as the single write/audit path.
- 2026-05-14: Recorded the Student ban regression caused by the updated `canManageGameUsers()` helper referencing `GameUser` in `CustomController` without importing `App\Models\GameUser`. The visible error was `Class "App\Http\Controllers\GameUser" not found`.
- 2026-05-14: Added a collapsible `Documentation` section beneath the existing sidebar sections, with `Game Guide` linking to `/documentation/game-guide`.
- 2026-05-14: Added the Staff-only `Class Intake System` documentation page at `/documentation/class-intake-multi-user-system`, backed by Laravel private Markdown at `storage/app/private/docs/staff/class-intake-multi-user-system.md`.
- 2026-05-14: Added the delete-timeout game rule: if enabled, a Student who deletes an active fellow Student is locked out of User Management writes for the configured number of minutes, defaulting to 5.
- 2026-05-14: Recorded the first Dashboard Documentation implementation direction: Markdown files live in Laravel `storage/app/private/docs`, are served through a controlled endpoint, and render in a React `Documentation` sidebar section.
- 2026-05-15: Recorded that timeout-blocked User Management writes should produce immediate toast feedback and a persisted Notification for the acting Student. Also recorded that unbanning a Student should create a Notification, matching the existing ban Notification behaviour.
- 2026-05-15: Expanded the timeout rule so both Student ban and Student delete actions can trigger lockdown. User Management now needs an error-gradient countdown strip, frontend action blocking, countdown toast feedback, and persisted Notifications for blocked attempts.
- 2026-05-15: Noted a future discussion point for a higher `Super Admin` role.
- 2026-05-15: Added the Staff-intake assignment direction: only Staff users with Admin powers see the User Management Class Intake selector, Protectors/Trainers use linked intakes, unlinked Protectors/Trainers receive an info-gradient warning, and `staff_intake_assignments` replaces the legacy single-trainer assumption for future work.
- 2026-05-15: Added the first Class Intake Management assignment operation: Staff users with Admin powers can load real intake/staff assignment data and save the assigned Staff list for a selected intake.
