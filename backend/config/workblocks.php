<?php
return ['password'=>env('WORKBLOCKS_PASSWORD'),'backup_directory'=>env('BACKUP_DIRECTORY',storage_path('app/backups'))];
