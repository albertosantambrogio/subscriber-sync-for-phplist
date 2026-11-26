# Subscriber Sync for phpList

WordPress plugin that subscribes new WordPress users to a phpList list
(public or private) and can e-mail the site owner a digest of new subscribers.

> **Status:** early development. Not ready for production.

- Design: [`docs/DESIGN.md`](docs/DESIGN.md)
- Server side (phpList plugin) and wire protocol:
  [phplist-plugin-wp-subscriber-sync](https://github.com/albertosantambrogio/phplist-plugin-wp-subscriber-sync)
- wordpress.org readme: [`readme.txt`](readme.txt)

## Layout

```
subscriber-sync-for-phplist.php   bootstrap
includes/                         classes (to be written)
languages/                        translations (.pot)
docs/                             design notes, not shipped
```

Files listed in `.distignore` are excluded from the zip/SVN release.

## License

GPL-2.0-or-later.
