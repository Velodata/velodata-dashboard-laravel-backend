# Codex Project Instructions

This VS Code setup commonly contains two separate codebases:

- Frontend: D:\Documents\SourceTree Repos\Dashboard\reactjs-dashboard
- Backend:  D:\Documents\SourceTree Repos\Dashboard\laravel-json-api-pro

Treat them as separate filesystem roots. Do not describe the frontend repo as "the workspace" when discussing backend files.

## Permission And Flow Rules

- Never request elevated permissions unless the user explicitly asks for elevated execution in the current task.
- Elevated-permission prompts stop the entire processing flow until the user returns to the Codex window and answers. If the user is working in another window, answering email, or away from the keyboard, the task stalls completely. Treat that interruption as a workflow failure, not a normal confirmation step.
- If a command fails because of sandboxing, report the blocked command and continue only with non-escalating, in-sandbox alternatives.
- Do not run optional verification commands unless the user explicitly asks for them in the current task.
- Never run Jest, including `npm test`, `npx jest`, `react-scripts test`, or targeted Jest test commands, unless the user explicitly gives permission for Jest in the current task. Adding or editing frontend tests does not imply permission to run Jest.
- Never do any of the following again: run Jest without permission; attempt `npm run build` without permission; request elevated permissions, which the user has explicitly ruled out; make deployment-package judgment calls beyond the exact documented/live command shape instead of sticking strictly to the user's established process.

## Generated Files And Packages

Never create deployment packages, zip files, temporary staging folders, logs, generated archives, or disposable scratch files inside any project repository unless the user explicitly requests that exact location.

Use this external scratch area for generated outputs:

```text
D:\Documents\SourceTree Repos\Dashboard\CodexScratch
```

Preferred subfolders:

```text
D:\Documents\SourceTree Repos\Dashboard\CodexScratch\packages
D:\Documents\SourceTree Repos\Dashboard\CodexScratch\logs
D:\Documents\SourceTree Repos\Dashboard\CodexScratch\temp
```

If the folder does not exist, ask before creating it. Do not fall back to creating generated-output folders inside `reactjs-dashboard` or `laravel-json-api-pro`.

## Deployment Package Rule

Deployment zip files for Laravel or React must be created under:

```text
D:\Documents\SourceTree Repos\Dashboard\CodexScratch\packages
```

Never create a `deployment-packages` folder inside either repo.

## NPM Rule

Do not run `npm start`, `npm run build`, or other npm commands unless the user explicitly asks for that command in the current task.
