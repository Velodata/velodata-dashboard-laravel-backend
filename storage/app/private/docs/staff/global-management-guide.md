# Global Management Guide

## Index

- [Purpose](#purpose)
- [Who Should Use It](#who-should-use-it)
- [Class Intake Selection](#class-intake-selection)
- [Course Week Presets](#course-week-presets)
- [Pane 01: Game Controls](#pane-01-game-controls)
- [Pane 02: Game Vulnerabilities](#pane-02-game-vulnerabilities)
- [Pane 03: Roles And Spies](#pane-03-roles-and-spies)
- [Pane 04: Elimination And Recovery](#pane-04-elimination-and-recovery)
- [Delete Timeout](#delete-timeout)
- [Reset And Baseline](#reset-and-baseline)
- [Login Security](#login-security)
- [Email Delivery](#email-delivery)
- [Operational Notes](#operational-notes)

## Purpose

Global Management is the staff control area for tuning the classroom game.

It lets staff configure how hard the Dashboard game is for a selected Class Intake, including which defensive rules are active, which vulnerabilities remain open, and what recovery tools are available if a class needs to be reset.

## Who Should Use It

Global Management is intended for Staff users.

- Staff Admin users can access the full Global Management UI.
- Trainer users can access the class/game tuning areas they need for teaching.
- Reset Baseline, Login Security, and Email Delivery remain Staff Admin-only areas.
- Students must not see Global Management, even if they have gained Admin powers inside the game.

Student Admin powers and Staff Admin powers are deliberately different.

## Class Intake Selection

Class Intake Selection controls which intake the game settings apply to.

This matters when one Trainer is linked to more than one Class Intake, or when multiple intakes are running on different teaching schedules.

Rules:

- Select the Class Intake first.
- The settings in Pane 01-04 are scoped to the selected intake.
- Changing intake should load that intake's current settings.
- A Trainer should only tune the intakes they are linked to.
- Staff Admin users can manage broader intake settings.

## Course Week Presets

Course week presets are quick-start configurations for the game.

Selecting a week turns on a known bundle of settings for Pane 01-04.

The current week model is:

- Week 1: Wide Open
- Week 2: Audit Trail
- Week 3: Protector Powers
- Week 4: Location Barriers
- Week 5: Login Hardening
- Week 6: Full Lockdown

The intended teaching model is progressive hardening. The system starts easier to exploit and becomes harder as students learn how the weaknesses work.

## Pane 01: Game Controls

Pane 01 contains defensive rules that can be switched on as the intake progresses.

These settings are the normal first place to tune how strict the class game should be.

Use Pane 01 when you want to control whether the game should allow or block broad account-management behaviours.

## Pane 02: Game Vulnerabilities

Pane 02 contains intentional weaknesses.

Some weaknesses exist because they are part of the teaching exercise. Do not assume every vulnerability in this pane is a bug.

Use Pane 02 when you want to decide which loopholes remain available for the current Class Intake.

## Pane 03: Roles And Spies

Pane 03 relates to role behaviour, privileged role selection, Spy/Protector mechanics, and identity uncertainty.

The Spy role is still an unfinished design area. Treat these settings as game-design controls, not normal business administration settings.

## Pane 04: Elimination And Recovery

Pane 04 controls elimination-style rules and recovery behaviour.

This area matters when students ban, delete, or otherwise knock each other out of play.

Use Pane 04 carefully because these settings can change whether an action is simply recorded, triggers a lockout, or affects whether a player remains active.

## Delete Timeout

Delete Timeout controls temporary lockout behaviour after dangerous Student actions.

The main teaching use is to discourage reckless banning or deleting while still allowing those actions to exist inside the game.

Current direction:

- Student ban/delete actions can trigger a temporary lockout.
- The lockout duration is configurable.
- The default teaching value has been 5 minutes.
- Timeout-blocked actions should produce clear feedback and notification records.

## Reset And Baseline

Reset And Baseline is a Staff Admin recovery area.

It supports restoring known-good data when a classroom game becomes too damaged to continue cleanly.

Current direction:

- Users table baselines can be captured and restored.
- Student intake baselines are being expanded so a selected Class Intake can be captured or restored independently.
- Baseline operations should be handled carefully because they can delete extra records and recreate missing snapshot records.

## Login Security

Login Security is a Staff Admin-only area.

It controls login hardening features such as two-factor login behaviour.

These settings affect authentication flow, so they should not be exposed to Trainers or Students unless the access model is deliberately changed later.

## Email Delivery

Email Delivery is a Staff Admin-only area.

It controls email-related operational settings, including where security/login messages may be sent.

Treat this as infrastructure configuration, not classroom game tuning.

## Operational Notes

- Always confirm the selected Class Intake before changing week presets or Pane 01-04 settings.
- Trainers may be teaching different intakes on different schedules.
- Week presets are useful, but fine tuning may still be needed.
- Global Management controls should be tested with a real Trainer account and a real Staff Admin account after permission changes.
- If a setting appears to save but does not change behaviour, check whether the feature has frontend-only UI or completed Laravel backend support.
