# How Starfleet Innotech Inc. Deploys

This guide explains how this project deploys from GitHub to the cPanel hosting server, what happens behind the scenes, and how to operate and maintain it.

---

## The Short Version

Every time changes are pushed to the `main` branch on GitHub (`https://github.com/KCIVOR/SFIO.git`), the live website at `public_html/Starfleet-Innotech-Inc` updates automatically within 10–15 seconds without manual file uploads or FTP client software.

---

## Deployment Architecture

```
Local Machine (git push)
        │
        ▼
GitHub Repository: KCIVOR/SFIO (main branch)
        │
        ▼
GitHub Actions (.github/workflows/deploy.yml)
        │
        ├─► 1. UAPI VersionControl/update
        │      cPanel pulls latest commit from GitHub into
        │      /home/starfleetinnotec/repositories/SFIO
        │
        └─► 2. UAPI VersionControlDeployment/create
               cPanel triggers .cpanel.yml task:
               rsync syncs code locally into
               /home/starfleetinnotec/public_html/Starfleet-Innotech-Inc/
```

---

## Server & Configuration Details

| Parameter | Value |
|---|---|
| **cPanel Server URL** | `https://srv489465.hstgr.cloud:2083` (or via `CPANEL_HOST`) |
| **cPanel User** | `starfleetinnotec` |
| **cPanel Repository Path** | `/home/starfleetinnotec/repositories/SFIO` |
| **Live Target Directory** | `/home/starfleetinnotec/public_html/Starfleet-Innotech-Inc/` |
| **Production Branch** | `main` |
| **CI/CD Workflow** | `.github/workflows/deploy.yml` |
| **cPanel Deploy Spec** | `.cpanel.yml` |

---

## GitHub Secrets & Variables

Configured under **Repository Settings ➔ Secrets and variables ➔ Actions**:

1. **Secrets**:
   - `CPANEL_TOKEN`: The API token created inside cPanel (**Manage API Tokens**).
2. **Variables (Optional)**:
   - `CPANEL_HOST`: Override the default host if the server URL or domain changes (defaults to `https://srv489465.hstgr.cloud:2083`).

---

## Setting Up cPanel Git™ Version Control (One-Time Setup)

If the repository has not yet been cloned into cPanel:

1. Log into your **cPanel** account (`starfleetinnotec`).
2. Navigate to **Git™ Version Control** (under the **Files** section).
3. Click **Create**.
4. Configure the repository:
   - **Clone URL**: `https://github.com/KCIVOR/SFIO.git`
   - **Repository Path**: `repositories/SFIO` (full path becomes `/home/starfleetinnotec/repositories/SFIO`)
   - **Repository Name**: `SFIO`
5. Click **Create**.
6. On the repository page, click the **Deploy** tab:
   - Verify that cPanel detects `.cpanel.yml`.
   - Click **Deploy HEAD Commit** once to verify initial synchronization.

---

## What Gets Protected on the Server

The deployment task in `.cpanel.yml` uses `rsync -a --delete` with strict exclusions to prevent overwriting production data:

- **`.env`**: Server credentials (SMTP credentials, passwords) live only on the server and are strictly excluded.
- **`.git` & `.github`**: Version control metadata is never placed into `public_html`.
- **`*.log` & `.ftpquota`**: Runtime logs and server quota metadata remain intact.

---

## Manual Fallback

If GitHub Actions is ever unavailable or an emergency manual deploy is needed:

1. Log into **cPanel** ➔ **Git™ Version Control**.
2. Find `SFIO` and click **Manage**.
3. Go to the **Pull or Deploy** tab.
4. Click **Update from Remote** to pull the latest commits.
5. Click **Deploy HEAD Commit** to execute `.cpanel.yml` into `public_html/Starfleet-Innotech-Inc`.
