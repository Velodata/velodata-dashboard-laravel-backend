# Global Management Guide

## Index

- [Purpose](#purpose)
- [Who Should Use It](#who-should-use-it)
- [Class Intake Selection](#class-intake-selection)
- [How Saving Works](#how-saving-works)
- [Consequences Pane](#consequences-pane)
- [Week Presets](#week-presets)
- [Week One](#week-one)
- [Week Two](#week-two)
- [Week Three](#week-three)
- [Week Four](#week-four)
- [Week Five](#week-five)
- [Week Six](#week-six)
- [Delete Timeout](#delete-timeout)
- [Reset And Baseline](#reset-and-baseline)
- [Login Security](#login-security)
- [Email Delivery](#email-delivery)
- [Regression-Backed Rules](#regression-backed-rules)
- [Operational Notes](#operational-notes)

## Purpose

Global Management is the staff control area for tuning the classroom game.

It lets staff configure how hard the Dashboard game is for a selected Class Intake, including which defensive rules are active, which vulnerabilities remain open, and what recovery tools are available if a class needs to be reset.

The current GMUI is organised as week panes. A pane may contain settings whose historical option numbers do not match the pane number. Do not rename an option just because it has moved panes; the option number is part of the game language.

## Who Should Use It

Global Management is intended for Staff users.

- Staff Admin users can access the full Global Management UI.
- Trainer users can access the class/game tuning areas they need for teaching.
- Reset Baseline, Login Security, and Email Delivery remain Staff Admin-only areas.
- Students must not see Global Management, even if they have gained Admin or Spy powers inside the game.

Student Admin powers and Staff Admin powers are deliberately different.

## Class Intake Selection

Class Intake Selection controls which intake the game settings apply to.

Rules:

- Select the Class Intake first.
- The week pane settings are scoped to the selected intake.
- Changing intake loads that intake's current saved settings.
- The Dashboard remembers the last Class Intake you selected in Global Management for your logged-in account.
- If that intake still exists, returning to Global Management should reopen on that same intake.
- Trainers should only tune intakes they are linked to.
- Staff Admin users can manage broader intake settings.
- Existing saved intake settings override the defaults shown by new week presets.

If a default changes later, existing Class Intakes may still show their saved value until the setting is deliberately changed and saved.

## How Saving Works

GMUI changes are draft changes until they are saved.

Changing a checkbox or selecting a different week does not immediately activate that behaviour in Laravel. The user must use `Save Changes`.

When there are unsaved changes:

- The Consequences Pane appears near the top of the week controls.
- Week panes show a `Save your changes` button with an upward arrow.
- That button scrolls back to the Consequences Pane rather than saving directly.

This flow is intentional. Staff should review the consequence text before committing game changes.

## Consequences Pane

The Consequences Pane lists pending setting changes before they are saved.

It warns for both directions:

- OFF to ON
- ON to OFF

This matters because turning a rule off can be as important as turning it on. For example:

- Turning `01.02` OFF allows banned Students to log in again.
- Turning `02.01` OFF lets eligible Students see Add User and create users.
- Turning `02.04` OFF stops locking Students after they Delete or Ban someone.

The warning pane is regression-tested so Pane 01-04 options have consequence text when switched from ON to OFF.

## Week Presets

Week presets are quick-start configurations for the game.

The current week model is:

- Week 1: Wide Open
- Week 2: Audit Trail
- Week 3: Protector Powers
- Week 4: Location Barriers
- Week 5: Login Hardening
- Week 6: Full Lockdown

The intended teaching model is progressive hardening. The system starts easier to exploit and becomes harder as students learn how the weaknesses work.

Current default highlights:

- `01.02 Banned players cannot log in` is ON from Week One onward.
- `02.05 Admins or Protectors can undelete eliminated players` is ON from Week Two onward.
- `02.02 Students can no longer choose any role for new users` is ON by default only in Week Six.

## Week One

Week One currently contains:

- `01.02 Banned players cannot log in`

This is ON by default from Week One onward. Laravel also enforces it: banned Students cannot log in when this setting is active.

## Week Two

Week Two currently contains:

- `02.04 Students receive a lockdown when they Delete or Ban someone.`
- `02.05 Admins or Protectors can undelete eliminated players`

`02.04` affects Student users only. When active, a Student who bans or deletes another user can be locked out of further User Management edits for the timeout period.

`02.05` allows deleted users to be restored, but restore permissions are deliberately narrow: only Staff Admins and Staff Protectors can restore deleted users.

This setting also controls whether deleted users appear in User Management for restore work. If `02.05` is OFF, deleted users are hidden even from Staff Admin and Staff Protector viewers. If `02.05` is ON, eligible Staff Admin and Staff Protector users can see deleted users and restore them.

## Week Three

Week Three currently contains:

- `02.06 Staff user Protectors can now see, ban or delete spies`
- `03.02 Protectors can identify spies`
- `03.03 Only Protectors can ban or delete spies`
- `03.04 Spies can appear as other users in audit screens`

Spy and Protector play is now a live part of the game.

Role Spy has Admin-like powers for attacking other Students. Spy users are hidden from normal visibility surfaces unless the viewer is a Protector.

Protectors can be Staff Protectors or Student Protectors. Both identities are allowed to reveal Spy visibility in the relevant history/user views.

## Week Four

Week Four currently contains:

- `01.04 User edits must originate from Australia`
- `04.02 Winner is the last active eligible player`
- `04.03 Banned players count as eliminated`

`01.04` blocks covered User Management edits from outside Australia when active. Regression tests cover Role changes, Avatar changes, Basic Info changes, and Password changes for Staff and Student edit paths.

The remaining Week Four controls support elimination logic and winner detection design.

## Week Five

Week Five currently contains:

- `01.01 Manual login requires 2FA`

`01.01` is linked to the Login Security 2FA checkbox. Turning one on or off updates the other.

When `01.01` is turned ON, the system can start sending real 2FA emails during manual login. Staff should review the Consequences Pane before saving this change.

## Week Six

Week Six currently contains:

- `02.01 Students can no longer Add Users`
- `02.02 Students can no longer choose any role for new users.`

`02.01` blocks Students from adding users. The User Management UI hides the Add User button for Students, and Laravel blocks Student Add User requests while this setting is active.

`02.02` hides Role selection for Student-created fake accounts. Laravel also enforces the rule by creating the account as Creator when Students add a user while this setting is active.

## Delete Timeout

Delete Timeout controls temporary lockout behaviour after dangerous Student actions.

Current direction:

- Student ban/delete actions can trigger a temporary lockout.
- The lockout duration is configurable.
- The default teaching value has been 5 minutes.
- Timeout-blocked actions should produce clear feedback and notification records.
- This lockout applies to Student users only, not Staff users.

The Week Two `02.04` checkbox is linked to the Delete Timeout enabled checkbox, and vice versa.

## Reset And Baseline

Reset And Baseline is a Staff Admin recovery area.

It supports restoring known-good data when a classroom game becomes too damaged to continue cleanly.

Current direction:

- Users table baselines can be captured and restored.
- Student intake baselines can be captured or restored independently.
- Baseline operations should be handled carefully because they can delete extra records and recreate missing snapshot records.
- Restore actions show a local success or error alert under the Reset And Baseline selection area.
- A successful restore alert reports the restored row count and any extra rows removed when that information is returned by the server.
- Student Intake restores are tied to the selected Class Intake code, not a numeric intake id.

## Login Security

Login Security is a Staff Admin-only area.

It controls login hardening features such as two-factor login behaviour.

`01.01 Manual login requires 2FA` is linked to the Login Security 2FA checkbox. This linkage works both ways:

- Turning `01.01` on updates Login Security.
- Turning Login Security on updates `01.01`.
- Turning either one off updates the other.

## Email Delivery

Email Delivery is a Staff Admin-only area.

It controls email-related operational settings, including where security/login messages may be sent.

Treat this as infrastructure configuration, not classroom game tuning.

## Regression-Backed Rules

The following behaviours are protected by regression tests and should be treated as current system rules:

- Student-created accounts are created in `game_users`, not the real Staff `users` table.
- Student-created accounts inherit the creator's Class Intake.
- Students are scoped to their own Class Intake.
- Staff Protectors only see Students from linked intakes.
- Students cannot be assigned Trainer.
- Student Admins cannot delete Staff users or ban any Admin.
- Role Spy has Admin-like power to edit other Students.
- Spy users are hidden from User Management unless the viewer is a Protector.
- Spy actors are hidden from User Audit History unless the viewer is a Protector.
- Spy users are hidden from User Login History unless the viewer is a Protector.
- Protector means Staff Protector or Student Protector for Spy visibility.
- Staff and Student users receive notifications when their Role, Password, or Basic Info changes.
- `admin@velodata.org` / user table id `1` is sacrosanct and must never be edited, role-changed, password-changed, avatar-changed, banned, or deleted through the game.
- Only `admin@velodata.org` can change the Role Management view permission.

## Operational Notes

- Always confirm the selected Class Intake before changing week presets or week pane settings.
- Trainers may be teaching different intakes on different schedules.
- Week presets are useful, but fine tuning may still be needed.
- Existing saved settings can override newly changed defaults.
- Global Management controls should be tested with a real Trainer account and a real Staff Admin account after permission changes.
- If a setting appears to save but does not change behaviour, check whether the selected Class Intake already has a saved value and whether Laravel has backend support for that setting.
- If deleted users disappear from User Management, check Week Two setting `02.05` before treating it as a visibility bug.
