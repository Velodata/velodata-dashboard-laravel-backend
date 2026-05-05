# Codex Project Instructions

This VS Code setup commonly contains two separate codebases:

- Frontend: `D:\Documents\SourceTree Repos\reactjs-dashboard`
- Backend: `C:\xampp\htdocs\laravel-json-api-pro`

Treat them as separate filesystem roots. Do not describe the frontend repo as "the workspace" when discussing backend files.

## Generated Files And Packages

Never create deployment packages, zip files, temporary staging folders, logs, generated archives, or disposable scratch files inside any project repository unless the user explicitly requests that exact location.

Use this external scratch area for generated outputs:

```text
D:\CodexScratch
```

Preferred subfolders:

```text
D:\CodexScratch\packages
D:\CodexScratch\logs
D:\CodexScratch\temp
```

If the folder does not exist, ask before creating it. Do not fall back to creating generated-output folders inside `reactjs-dashboard` or `laravel-json-api-pro`.

## Deployment Package Rule

Deployment zip files for Laravel or React must be created under:

```text
D:\CodexScratch\packages
```

Never create a `deployment-packages` folder inside either repo.

## NPM Rule

Do not run `npm start`, `npm run build`, or other npm commands unless the user explicitly asks for that command in the current task.
