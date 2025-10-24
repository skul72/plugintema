```mermaid

flowchart TD
  A["📂 mu-plugins"] --> B["🧩 📂 pt-simple-backup"]
  B --> B0["📄 pt-simple-backup.php (bootstrap: defines + requires)"]

  B --> C["📂 inc"]
  C --> C1["📄 config.php (ptsb_cfg, tz, helpers de data)"]
  C --> C2["📄 rclone.php (ptsb_rclone, manifest read/write, listagem/keep)"]
  C --> C3["📄 log.php (ptsb_log, rotate, tail)"]
  C --> C4["📄 parts.php (letters↔parts, labels)"]
  C --> C5["📄 schedule.php (auto_*, cycles_*, cron tick)"]
  C --> C6["📄 actions.php (admin_post_* handlers)"]
  C --> C7["📄 ajax.php (ptsb_status, ptsb_details_batch)"]
  C --> C8["📄 ui.php (ptsb_render_backup_page + CSS/JS inline)"]

  B --> D["🎨 📂 assets"]
  D --> D1["📄 admin.css"]
  D --> D2["📄 admin.js"]
