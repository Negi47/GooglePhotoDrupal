1. Install docksal https://docksal.io/installation (on mac os - use virtualbox)
2. `cd to/your/folder/with/the/project/`
3. `fin system start`, wait
4. `fin start`, wait
5. download the db from acquia
6. unpack the database and put it somewhere in repo root
7. `fin bash`
8. Inside the container - go to site folder which holds `settings.php` `cd docroot/sites/[site-name]`
9. `drush sql-cli < /pacth/to/your/db.sql`, wait
10. `drush cim -y`, wait
11. `drush en stage_file_proxy -y`
12. use site with url `*.hologic-marketing.docksal` (`viera.hologic-marketing.docksal`, etc.)

