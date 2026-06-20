# Game Guide

## Index

- [Objective](#objective)
- [Class Intake Boundary](#class-intake-boundary)
- [Fake Accounts](#fake-accounts)
- [Roles](#roles)
- [Admin](#admin)
- [Spy](#spy)
- [Protector](#protector)
- [Creator](#creator)
- [Member](#member)
- [Trainer](#trainer)
- [Attacking Other Players](#attacking-other-players)
- [Notifications](#notifications)
- [Audit And Login History](#audit-and-login-history)
- [Bans, Deletes, And Recovery](#bans-deletes-and-recovery)
- [The System Admin Account](#the-system-admin-account)
- [Weekly Game Flow](#weekly-game-flow)
- [Week One](#week-one)
- [Week Two](#week-two)
- [Week Three](#week-three)
- [Week Four](#week-four)
- [Week Five](#week-five)
- [Week Six](#week-six)
- [What The Game Is Teaching](#what-the-game-is-teaching)

Welcome to the Velodata Dashboard game.

The game is a controlled cyber-security exercise inside your class intake. Your goal is to stay active, understand the permission system, and learn how account attacks, audit trails, fake accounts, and defensive roles interact.

## Objective

Stay on air longer than the other players in your class intake.

A player is usually considered on air when:

- They can log in.
- Their account can still produce heartbeats.
- Their account can still use the dashboard permissions its role gives it.

If a player is banned or deleted, they should lose useful access. From Week One onward, banned players cannot log in.

## Class Intake Boundary

Your class intake is your game arena.

- Student accounts are scoped to their own class intake.
- Fake accounts created by Students stay inside the creator's class intake.
- Students should not be able to manage Students from another intake.
- Staff users live in the real `users` table, separate from class-intake Student accounts.
- Staff Protectors only see Students from class intakes they are linked to.

## Fake Accounts

Students can create fake accounts when the current game settings allow it.

Fake accounts created by Students are created as `game_users`, not real Staff users. This matters because fake accounts belong to the class game, not the production Staff account table.

Depending on the current week settings:

- Students may be blocked from adding new users.
- Students may be blocked from choosing a role for new fake accounts.
- If role choice is blocked, the backend forces Student-created accounts to the Creator role.

## Roles

### Admin

Admin is powerful and obvious.

Admin-style accounts can perform many User Management actions, but Admin accounts are also highly visible in the interface and audit trail. A Student using a fake Admin account may attract attention quickly.

### Spy

Spy is powerful and stealthy.

Role Spy has Admin-like powers for attacking other Students. A smart player may learn that creating a fake account with Role Spy is usually more useful than creating a fake account with Role Admin.

Spy accounts are hidden from:

- User Management, unless the viewer is a Protector.
- User Audit History, unless the viewer is a Protector.
- User Login History, unless the viewer is a Protector.

This applies to both Staff Protectors and Student Protectors. Protectors are the counter-play to Spies.

### Protector

Protector is a defensive role.

Protectors can detect Spy activity that other roles cannot see. Staff Protectors and Student Protectors can see Spy users in User Management, User Audit History, and User Login History.

Staff Admins and Staff Protectors can restore deleted users when undelete is enabled.

### Creator

Creator can participate in account creation when the current game settings allow it.

Creator is useful early in the game, especially while fake-account creation is still open.

### Member

Member has limited power and is usually a safer, less privileged role.

### Trainer

Students cannot be assigned the Trainer role.

## Attacking Other Players

The game is designed so Students can attack fellow Students. Some roles give more attack power than others.

Attacks may include actions such as:

- Changing another Student's basic information.
- Changing another Student's role.
- Changing another Student's password.
- Banning or deleting another Student.
- Creating fake accounts to gain different powers.

Some actions may trigger notifications or audit records.

## Notifications

The system sends notifications for important account changes. Notifications are part of the game evidence trail, not just pop-up messages.

Users should receive notifications when their:

- Role is changed.
- Password is changed.
- Basic Info is changed.
- Account is banned, unbanned, deleted, or undeleted.
- User Management access is placed into timeout.
- Account access or protected-account activity creates a security warning.

This applies to both Staff users and Student game users.

The bell icon shows recent notifications. The User Notifications screen gives a searchable history so you can review older events and work out what happened.

The User Notifications screen also has a Time Zone selector. Use it to view notification Date / Time values in Perth, Sydney, Brisbane, or UTC time.

Useful notification filters include:

- `Role` for role changes.
- `Password` for password changes.
- `Profile` for basic-info, profile, or avatar changes.
- `Ban/Unban` for account ban and unban records.
- `Timeout` for User Management timeout notices.
- `Security` for protected-account warnings.

Do not assume a fake account hides everything. If an action affects your account, the notification history may still show who performed it, when it happened, and what changed.

## Audit And Login History

The system records important events.

User Audit History can show who performed actions and what happened. User Login History can show recent logins. However, Spy accounts are hidden from these views unless the viewer is a Protector.

This means Spy is not invisible to everyone. It is invisible to the wrong defenders.

## Bans, Deletes, And Recovery

From Week One onward, banned Students cannot log in.

If the delete timeout setting is enabled, Students who ban or delete another user can be locked out of further User Management edits for a period of time. This only affects Student users, not Staff users.

Timeout notifications show how long the timeout lasts and when it ends. During timeout you may still be able to view parts of the dashboard, but User Management write actions are blocked.

When undelete is enabled, deleted users can only be restored by Staff Admins or Staff Protectors.

## The System Admin Account

The account `admin@velodata.org` in user table id `1` is sacrosanct.

It should never be edited by anyone. It should not be role-changed, password-changed, avatar-changed, banned, deleted, or otherwise modified through the game.

This account is outside the game.

## Weekly Game Flow

The Global Management UI controls how the game changes over time. The visible panes are organised by week.

### Week One

Week One starts with banned players blocked from logging in.

### Week Two

Week Two introduces more recovery and consequence mechanics, including delete timeout and undelete behaviour.

### Week Three

Week Three focuses on Spy and Protector play. Protectors become important because they can reveal Spy activity.

### Week Four

Week Four introduces stronger location-based restrictions. User edits can be blocked when they originate from outside Australia.

### Week Five

Week Five introduces stronger login and account-creation pressure, including manual login 2FA and restrictions around Student-created accounts.

### Week Six

Week Six is a more locked-down phase. Role selection for new Student-created accounts can be restricted so Students cannot freely choose powerful roles.

## What The Game Is Teaching

The game rewards curiosity, but it also rewards understanding consequences.

Useful lessons include:

- Permissions are powerful.
- Visibility matters.
- Fake accounts can change the shape of an attack.
- Audit trails are only useful if defenders know where to look.
- Spy roles are dangerous until a Protector starts watching.
- Some accounts and boundaries are not part of the game and must remain protected.
