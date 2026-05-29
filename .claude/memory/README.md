# Claude Memory — TradingCRM

This folder contains Claude Code's auto-memory for this project, committed to git so it syncs across devices.

## Setup on a new device

After cloning this repo, link these files into Claude's harness-managed memory directory so Claude reads them on startup:

### Windows (PowerShell, run as admin)

```powershell
$src = "$PWD\.claude\memory"
$dst = "$env:USERPROFILE\.claude\projects\C--Users-$env:USERNAME-Documents-Work-TradingCRM\memory"
New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
if (Test-Path $dst) { Remove-Item -Recurse -Force $dst }
New-Item -ItemType SymbolicLink -Path $dst -Target $src
```

### macOS / Linux

```bash
SRC="$PWD/.claude/memory"
# Adjust the project path hash to match your local clone location
DST="$HOME/.claude/projects/$(pwd | sed 's|/|-|g')/memory"
mkdir -p "$(dirname "$DST")"
ln -sfn "$SRC" "$DST"
```

If symlinks aren't an option, just copy the files instead of linking.

## Files

- `MEMORY.md` — index of memories (always loaded into Claude context)
- `project_tradingcrm.md` — project background (stack, infra, conventions)
- `user_tech_preferences.md` — user preferences for tech choices

Update these as the project evolves; commit + push to share with all devices.
