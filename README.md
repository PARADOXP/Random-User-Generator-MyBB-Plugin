# Random User Generator for MyBB

An Admin CP tool that creates real MyBB accounts with random usernames. Names come from public name APIs, or from a built-in local name list when the API is unavailable. Each generated account gets a random password, a derived email address and an optional DiceBear avatar.

- **Version:** 1.1.0 (`@version` docblock still says 1.0.0)
- **Codename:** `random_user_generator`
- **Compatibility:** MyBB 1.8.x (`18*`)
- **Author:** `PARADOX987` in the plugin metadata, `PARAD0X` on the [MyBB profile](https://community.mybb.com/user-134311.html)
- **License:** GPL-3.0
- **Dependencies:** none. This plugin does not use PluginLibrary.

## Features

- Generates between 1 and 100 users in one request.
- Seven username styles: local names, fancy names, single word, two words, multi-word, name plus number, and a creative mix.
- Four name sources: Random User API, UI Names API, Faker API, or a custom JSON endpoint.
- Silent local fallback so generation still works when the chosen API fails.
- Selectable target usergroup.
- Optional maximum username length.
- Six DiceBear avatar styles, stored as a remote avatar URL.
- Generated passwords using MyBB's `random_str()` with mixed case, digits and symbols.
- Audit trail in a dedicated history table: username, email, password, generation type, admin and date.
- Admin log entry and an **Edit** link for every generated account.

## Requirements

- MyBB 1.8.x.
- PHP 7.0 or newer.
- cURL recommended. Without it the plugin falls back to `fetch_remote_file()`.
- Outbound HTTPS access for the selected API and for DiceBear avatars.
- The `iconv` extension for accent transliteration.

## Installation

1. Copy the contents of this repository over the forum root.
2. Go to **Admin CP → Configuration → Plugins**, find **Random User Generator** and click **Install**, then **Activate**.
3. Configure it under **Configuration → Settings → Random User Generator**.
4. Open **Users and Groups → Random User Generator**.

The installer creates `{TABLE_PREFIX}random_user_generator_history`. Activation registers the settings group and the generation options. Deactivation does nothing; uninstall drops the table and removes the settings group.

## Admin permissions

The plugin adds one permission to the **Users and Groups** module:

| Permission | Label |
| --- | --- |
| `user/random_user_generator` | Can use Random User Generator? |

MyBB's own `check_admin_permissions()` enforces it before `admin_load` runs, so the page and the generation POST are both gated. Super admins bypass it.

## Generation types

| Value | Output shape |
| --- | --- |
| `local_name` | `FirstLast` |
| `fancy_name` | `First_Last` or `First.Last` |
| `single_word` | `First` or `First123` |
| `two_words` | `CoolGamer`, `SuperPlayer` |
| `multi_word` | `Cool_John_Doe` |
| `username_number` | `JohnDoe123` |
| `creative` | `JohnTheDoe`, `TheJohnDoe`, `john_doe_123` |

All types pass through `random_user_generator_clean_username()`, which transliterates to ASCII and strips everything except letters, digits, `_` and `.`.

## Name sources

| Setting value | Endpoint | Notes |
| --- | --- | --- |
| `randomuser` | `https://randomuser.me/api/` | Supports batch (`?results=N`). |
| `uinames` | `https://uinames.com/api/` | No batch support. |
| `fakerapi` | `https://fakerapi.it/api/v1/persons?_quantity=N` | Supports batch. |
| `custom` | Value of **Custom API URL** | Expects top-level `first` / `last` / `name` fields. No batch. |

If the selected API fails, returns invalid JSON or returns fewer names than requested, the plugin fills the gap from its local first/last name lists. There is no error message for this.

## Settings

Seven settings are registered in the `random_user_generator` group.

| Setting | Default | Purpose |
| --- | --- | --- |
| API Choice | Random User API | Which name source to use. |
| Custom API URL | empty | Endpoint used when API Choice is `custom`. |
| Default Usergroup | `2` | Pre-selected group in the form. |
| Default Password Length | `12` | Passwords shorter than 8 are forced to 12. |
| Email Domain | `example.com` | Domain appended to generated addresses. |
| Send Welcome Email | No | Declared but never used. |
| API Timeout | `10` | Seconds; values below 5 are raised to 5. |

## How generation runs

1. The form POSTs to `index.php?module=user-random_user_generator&action=generate` and is checked with `verify_post_check()`.
2. `num_users` is clamped to 1–100; a missing generation type aborts with an error.
3. `random_user_generator_fetch_batch()` calls the chosen API once. `randomuser` and `fakerapi` support a `results`/`_quantity` parameter; `uinames` and `custom` do not, so those two silently generate every name locally.
4. Each name is passed to `random_user_generator_create_user_with_data()`, which:
   - appends `1`, `2`, … up to 100 if the username exists,
   - derives `<sanitized username>@<email domain>`,
   - generates a password with `random_str()`,
   - builds a DiceBear avatar URL,
   - validates and inserts through `UserDataHandler`,
   - writes a history row and an admin log entry.
5. The page prints a table of username, email, password and an Edit link, plus the number of failures.

Because the batch function always fills up to the requested count, the per-user path (`random_user_generator_create_user()`) is never reached from the UI.

## Database

```
{TABLE_PREFIX}random_user_generator_history
  id               auto increment / serial
  uid              generated user id
  username         generated username
  email            generated email
  password         generated password, stored in plain text
  generation_type  selected type, or "batch" for batch-created users
  generated_by     admin uid
  dateline         timestamp
```

Indexes: primary `id`, `uid` on MySQL only. There is no index on `dateline` or `generated_by`.

## Hooks

| Hook | Function | Purpose |
| --- | --- | --- |
| `admin_user_menu` | `random_user_generator_admin_menu` | Adds the page under **Users and Groups**. |
| `admin_user_action_handler` | `random_user_generator_admin_action` | Declares the `random_user_generator` action. |
| `admin_user_permissions` | `random_user_generator_admin_permissions` | Adds the permission key. |
| `admin_load` | `random_user_generator_admin` | Renders the generation form, results and history. |

## Repository layout

```
inc/
├── languages/
│   └── english/
│       └── admin/
│           └── config_random_user_generator.lang.php
└── plugins/
    └── random_user_generator.php
```

This is a single-file plugin: 1,372 lines, no folder, no templates, no CSS/JS and no front-end language file.

## Known defects and limitations

These are properties of the current source, documented rather than fixed.

### Security

1. **Generated passwords are stored and displayed in plain text.** `random_user_generator_history.password` holds the password, the results table prints it, and the history page prints it again. Anyone with database read access, a backup, or the history page sees every generated account's credentials. The password should be shown once and never persisted.
2. **TLS certificate verification is disabled.** `random_user_generator_http_request()` sets `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` to `false`, so a man-in-the-middle can return arbitrary names and (indirectly) influence usernames and email addresses.
3. **The target usergroup is not validated.** `UserDataHandler::verify_usergroup()` returns `true` unconditionally, and the plugin casts the POST value with `(int)`. A request with `usergroup=1` creates an Administrator even though the form excludes group 1. Any admin with the plugin permission but not user-group management can therefore mint admin accounts.
4. **The custom API URL is unrestricted.** Any scheme and host is accepted, redirects are followed (`CURLOPT_FOLLOWLOCATION`), and there is no allowlist. Because the setting is admin-controlled this is not remote SSRF, but it does let an admin point the plugin at internal HTTP services; JSON responses are consumed as names.
5. **Uninstall confirmation is fake.** `random_user_generator_uninstall()` calls `$page->output_confirm_action()` when the request is not a POST but does not `exit`. The function then deletes the settings group, deletes every `rug_%` setting, rebuilds settings and drops the table on that same first GET. The admin sees an "Are you sure?" page after the data is already gone, and the `no` branch can never run.
6. **Uninstall deletes settings by prefix.** `DELETE FROM settings WHERE name LIKE 'rug_%'` is not scoped to the plugin's group, so any other setting starting with `rug_` is removed too.

### Generation behaviour

7. **UI Names and Custom API are never used.** `random_user_generator_fetch_batch()` only has `randomuser` and `fakerapi` branches. For the other two choices it returns a local list of the full requested size, so the real API is never called and the admin receives no warning.
8. **The per-user API path is dead code.** Because the batch function always fills to `num_users`, the `$remaining` loop is always zero and `random_user_generator_create_user()` / `random_user_generator_fetch_username()` never execute from the UI.
9. **Send Welcome Email does nothing.** The setting is registered and labelled, but no code reads `rug_send_welcome_email`.
10. **Batch-created users are logged as type `batch`.** The history loses the generation type the admin selected; every batch row says `batch`.
11. **Suffixed emails do not match suffixed usernames.** In `create_user_with_data()` the email is derived before the duplicate loop; when `JohnDoe` becomes `JohnDoe1`, the email stays `johndoe@…` (and can collide with the original user's email). In `create_user()` the email is only refreshed inside the loop, so the two paths disagree.
12. **The duplicate suffix can exceed the username limit.** `$base_username` is formatted and trimmed first, then `1`, `2`, … is appended with no re-trim. A name at the maximum length becomes `Name123`, which MyBB rejects.
13. **Username length is not clamped to MyBB's limit.** The form allows 50 characters and the setting passes it through; MyBB's `maxnamelength` is 30 by default. Anything longer fails validation and is only counted as a failed user.
14. **Trimming is byte-based.** `format_username()` uses `strlen()` and `substr()`, so multi-byte names can be cut mid-character into invalid UTF-8.
15. **`iconv()` is required but not checked.** `random_user_generator_clean_username()` calls `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', …)` directly. If the extension is missing or the input is invalid UTF-8, the result can be `false`, which is then passed to `preg_replace()` and turns into an empty username.
16. **API failures are invisible.** Every failure path returns `success => false` and falls back to local names. `rug_error_api_failed` and `rug_error_validation` are defined in the language file but never used, and per-user validation details are discarded (only a count of failures is shown).
17. **The custom API parser is shallow.** It only reads top-level `first` / `last` / `name` / `surname`. Nested payloads (for example `{"data":{...}}`) and list payloads silently produce the literal name `User`.
18. **No retry on validation failure.** A generated username that is reserved, too long, or otherwise invalid is counted as failed; the plugin does not try another name.
19. **`insert_user()` is not checked for failure.** Both creation paths read `$user_info['uid']` directly. If the insert returns `false`, the history row gets uid `0` and PHP warnings are emitted.
20. **No time limit and no chunking.** Up to 100 users are created in a single request, each with a `UserDataHandler` insert, a history insert and an admin log insert. Slow boards can hit `max_execution_time`; there is no `my_set_time_limit()` and no resumable batching.
21. **The ACP header is printed before the slow work.** `output_header()` runs before the API call and before the inserts, so a slow or failed generation leaves the admin on a half-rendered page.
22. **Password length has no upper bound.** The setting is cast to `int`; a large value makes `random_str()` build an arbitrarily large string.
23. **The email domain is barely sanitised.** Only `@` is removed and the value trimmed. A domain containing spaces, slashes or a trailing dot passes through to `validate_email_format()` and fails per user.
24. **`local_name` and friends can produce trailing separators.** With an empty last name from a custom API, `fancy_name` yields `First.`, `multi_word` yields `Cool_First_`, and similar output may fail validation.

### Localisation and UI

25. **Several labels are hardcoded English.** The history table header `Password`, the six avatar style labels and their description, the API names in `random_user_generator_get_api_name()`, and the plugin `description` in `_info()` are not language strings.
26. **`website` is malformed.** `_info()` returns `https:mybb.epizy.com` instead of `https://mybb.epizy.com`.
27. **Version metadata disagrees.** The file docblock says `@version 1.0.0`; `_info()` says `1.1.0`.
28. **Author name does not match the project standard.** `_info()` uses `PARADOX987`; the rest of the published plugins use `PARAD0X`.
29. **The history page is a fixed 100-row list.** No pagination, no search, no delete action, and no index on the `dateline` sort column.
30. **The history page runs N+1 queries.** `get_user($row['generated_by'])` is called once per row.
31. **Routing keys on the module string, not the active action.** `random_user_generator_admin()` returns unless `module=user-random_user_generator`. If the page is reached with `module=user` and `action=random_user_generator`, the handler returns and MyBB tries to include the action file declared as `""`, which is not a valid path.
32. **The auto-upgrade runs from the page controller.** `random_user_generator_admin()` repeats the `ALTER TABLE … ADD password` logic already present in `random_user_generator_install()`, on every page load while the column is missing, with no error handling or schema version.
33. **`login` is computed but never used.** `random_user_generator_format_username()` accepts `$data['login']` and ignores it in every branch.
34. **Name casing is flattened.** `ucfirst(strtolower(...))` turns `McDonald` into `Mcdonald` and mangles mixed-case names before transliteration.
35. **Uninstall leaves generated accounts behind.** Dropping the table removes the audit trail but not the users, their content or their admin log entries.
36. **No cleanup of stale permission rows.** Removing the plugin leaves `random_user_generator` keys in existing admin permission records.

## Compatibility notes

- MyBB 1.8 only. The plugin uses the 1.8 `UserDataHandler`, `Table`, `Form` and `FormContainer` ACP classes.
- The `admin_load` route assumes the module is requested as `user-random_user_generator`, which is how the menu link is built.
- MySQL uses MyISAM for the history table; PostgreSQL and SQLite have separate `CREATE TABLE` variants.
- DiceBear avatars are fetched by the browser from `api.dicebear.com`; no local copy is made.

## License

GPL-3.0. See [LICENSE](LICENSE).
