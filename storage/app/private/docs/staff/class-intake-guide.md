# Class Intake Guide

## Index

- [Purpose](#purpose)
- [Who Should Use It](#who-should-use-it)
- [Class Intakes](#class-intakes)
- [Staff Assignments](#staff-assignments)
- [Add Student](#add-student)
- [Linked Staff Visibility](#linked-staff-visibility)
- [User Management Context](#user-management-context)
- [Students And Fake Accounts](#students-and-fake-accounts)
- [Admin And Trainer Responsibilities](#admin-and-trainer-responsibilities)
- [Notifications](#notifications)
- [Common Checks](#common-checks)
- [Troubleshooting](#troubleshooting)

## Purpose

Class Intake Management controls the relationship between Staff users and student Class Intakes.

It exists so the Dashboard can support many students, many intakes, and many Staff trainers at the same time without exposing one class intake's students to another class intake.

## Who Should Use It

Class Intake Management is intended for Staff users with Admin-level access.

- Staff Admin users can assign Staff members to Class Intakes.
- Trainers and Protectors can use the resulting linked-intake visibility in User Management.
- Students must not see Class Intake Management.
- Student Admin powers and Staff Admin powers are deliberately different.

## Class Intakes

A Class Intake is a teaching group using the Dashboard game.

Each intake has:

- an intake code, such as `EQ-CYBER-24-14`
- an intake name
- a current week
- a status
- a group of Student/game-user records in `game_users`

Class Intake codes are important because numeric database IDs can differ between localhost and production.

When code and ID are both available, the intake code should be treated as the stable identifier.

Class Intake Management now loads roster data in two steps:

- the first request loads the known Class Intake list and Staff assignment context
- the roster request loads the selected class by intake code

This avoids showing the wrong roster when numeric database IDs differ between development and production.

## Staff Assignments

Staff Assignments link Staff users to a selected Class Intake.

The assignment workflow is:

- Open Class Intake Management.
- Select the intake on the left.
- Review the intake summary on the right.
- Use the Staff Assignments pane to choose active Staff members.
- Save assignments.

Only active Staff users should appear as candidates.

Users marked as BANNED or DELETED are automatic non-qualifiers.

## Add Student

Staff Admin users can add a Student directly from Class Intake Management.

The Add Student pane creates a Student game user in `game_users`. It does not create a Staff account in `users`.

Use the Class Intake dropdown to choose the destination intake. The dropdown is code-based, so the selected intake is identified by a value such as `EQ-CYBER-26-04`.

Required fields:

- Class Intake
- First name
- Email Address
- Password

The email field is labelled `Email Address (can't be changed after Student is created)` because the email becomes the Student login identifier.

Optional profile fields match the My Profile style:

- Company Name
- My Gender
- My Location
- Phone Number
- My Languages

Surname and Preferred name are not collected in this pane.

New Students are created as:

- Role: `Creator`
- Status: `active`
- Password: the password entered by the Staff Admin
- Must change password: yes

After adding a Student, select or refresh that Class Intake roster to confirm the Student appears in the expected class.

## Linked Staff Visibility

Linked Staff visibility controls what non-Admin Staff users can see.

Current direction:

- Staff Admin users can see all intakes because they manage the training structure.
- Trainers and Protectors should see Students only from intakes linked to them.
- If a Trainer or Protector has no linked intake, User Management should show a clear warning and no Student rows.
- Linked-intake access should eventually apply consistently to User Management, User Audit History, and User Login History.

## User Management Context

User Management uses the selected Class Intake context.

For Staff Admin users:

- the Class Intake selector is visible
- changing intake changes the Student rows shown in User Management
- Staff rows still come from `users`
- Student rows come from `game_users` for the selected intake

For Student/game users:

- the Class Intake selector is not visible
- they are always scoped to their own intake
- browser-selected intake context must not be trusted for Student visibility

## Students And Fake Accounts

Students and fake game accounts live in `game_users`.

Staff users live in `users`.

When a Student creates a fake account through Add User:

- the new account must be written to `game_users`
- the fake account must inherit the creator Student's Class Intake
- creator details should be recorded in metadata
- the fake account remains inside the same intake boundary as the creator

This is intentional game behaviour, not normal Staff account creation.

## Admin And Trainer Responsibilities

Staff Admin responsibilities:

- create or manage Class Intake structure
- link Staff users to intakes
- control who can see which intake
- investigate cross-intake visibility problems
- use Global Management for week/game settings

Trainer responsibilities:

- work inside the intakes they are linked to
- use User Management to monitor the class population
- avoid assuming another intake is visible unless it has been assigned

## Notifications

When Staff assignments change, notifications help Staff users understand why access appeared or disappeared.

Current direction:

- A Staff user should receive a notification when linked to a new Class Intake.
- A Staff user should receive a notification when access to a Class Intake is removed.
- Assignment save feedback should be clear to the Staff Admin performing the change.

## Common Checks

If a Staff user cannot see Students:

- confirm they are active
- confirm they are linked to the correct Class Intake
- confirm the selected intake is the expected intake
- confirm the Student records exist in `game_users`
- confirm the intake code matches the expected class

If a Student can see the wrong class:

- treat it as an intake isolation bug
- check whether frontend context was trusted instead of the logged-in Student's own intake
- check whether backend filtering used intake ID instead of intake code

If Add Student fails:

- confirm a Class Intake was selected
- confirm the request sent `game_intake_code`
- confirm the email address is not already used by an existing Student or Staff account
- confirm the latest Laravel migrations have run
- confirm `game_users.company_name` exists and `game_users.surname` allows null values

## Troubleshooting

Missing assignment table:

- If Staff Assignment data fails with a missing table message, run Laravel migrations.
- The relevant table is `staff_intake_assignments`.

Wrong Staff candidate list:

- Check that banned/deleted Staff are filtered out.
- Check that candidates are Staff users, not Student/game users.

Wrong Student list:

- Check the selected intake code.
- Check the logged-in identity type.
- Check whether the viewer is Staff Admin, linked Trainer/Protector, or Student.

Unexpected access:

- Remember that Staff Admin and Student Admin are not equivalent.
- Student Admin powers are part of the game.
- Staff-only management surfaces must remain Staff-only.
