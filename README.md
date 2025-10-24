flowchart TD
    A["📂 mu-plugins"] --> B["🧩 📂 pt-simple-backup"]

    %% raiz do plugin
    B --> B0["📄 pt-simple-backup.php\n(bootstrap: defines + requires)"]

    %% pasta inc
    B --> C["📂 inc"]
    C --> C1["📄 config.php\n(ptsb_cfg, tz, helpers de data)"]
    C --> C2["📄 rclone.php\n(ptsb_rclone, manifest read/write, listagem/keep)"]
    C --> C3["📄 log.php\n(ptsb_log, rotate, tail)"]
    C --> C4["📄 parts.php\n(letters↔parts, labels)"]
    C --> C5["📄 schedule.php\n(auto_*, cycles_*, cron tick)"]
    C --> C6["📄 actions.php\n(admin_post_* handlers)"]
    C --> C7["📄 ajax.php\n(ptsb_status, ptsb_details_batch)"]
    C --> C8["📄 ui.php\n(ptsb_render_backup_page + CSS/JS inline)"]

    %% assets
    B --> D["🎨 📂 assets"]
    D --> D1["📄 admin.css"]
    D --> D2["📄 admin.js"]
