#!/usr/bin/env python3
"""Isolated MySQL/PHP upgrade tests; never mounts production volumes or publishes ports."""
import argparse
import concurrent.futures
import io
import pathlib
import shutil
import subprocess
import tarfile
import tempfile
import time
import uuid


def command(args, *, data=None, expect=0):
    result = subprocess.run(args, input=data, capture_output=True, timeout=180)
    if result.returncode != expect:
        raise RuntimeError(f"Command failed ({result.returncode}): {' '.join(args[:5])}\n"
                           + result.stdout.decode(errors="replace") + result.stderr.decode(errors="replace"))
    return result.stdout.decode(errors="replace")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', default='acg-faka:3.7.6-upgrade-fixed', help='Local upgrade image built from this checkout')
    parser.add_argument('--mysql-image', default='mysql:8.0')
    parser.add_argument('--rollback-image', help='Also test the compatibility rollback image')
    parser.add_argument('--images-only', action='store_true', help='Run only application image smoke checks')
    args = parser.parse_args()
    repo = pathlib.Path(__file__).resolve().parents[2]
    token = uuid.uuid4().hex[:10]
    network, mysql, app = (f'acg-upgrade-{token}-{part}' for part in ('net', 'db', 'app'))
    with tempfile.TemporaryDirectory(prefix='acg-upgrade-test-') as temp:
        root = pathlib.Path(temp) / 'app'
        root.mkdir()
        archive = subprocess.check_output(['git', 'archive', 'HEAD'], cwd=repo)
        with tarfile.open(fileobj=io.BytesIO(archive)) as bundle:
            bundle.extractall(root)
        changed = subprocess.check_output(['git', 'diff', '--name-only', 'HEAD'], cwd=repo).decode().splitlines()
        changed += subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard'], cwd=repo).decode().splitlines()
        for name in changed:
            source, target = repo / name, root / name
            if source.is_file():
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, target)
        old_sql = subprocess.check_output(['git', 'show', '9927235:kernel/Install/Install.sql'], cwd=repo).replace(b'__PREFIX__', b'acg_')
        new_sql = (root / 'kernel/Install/Install.sql').read_bytes().replace(b'__PREFIX__', b'acg_')
        (root / 'config/database.php').write_text("<?php return ['driver'=>'mysql','host'=>'" + mysql + "',"
            "'database'=>'upgrade_test','username'=>'root','password'=>'upgrade-test-only','charset'=>'utf8mb4',"
            "'collation'=>'utf8mb4_unicode_ci','prefix'=>'acg_','options'=>[PDO::ATTR_TIMEOUT=>3]];")
        (root / 'kernel/Install/Lock').write_text('')
        for name, destination in [('Info.php', 'Config'), ('Signature.php', 'Impl'), ('Pay.php', 'Impl')]:
            target = root / 'app/Pay/Epay' / destination / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(root / 'tests/upgrade/fixtures' / name, target)
        php_base = ['docker', 'run', '--rm', '--platform', 'linux/amd64', '--network', network,
                    '--entrypoint', 'php', '-v', f'{root}:/var/www/html', args.image,
                    '-d', 'error_reporting=22527']

        def php(file, *arguments, expect=0):
            return command(php_base + [file, *arguments], expect=expect)

        # Keep application code inside the image; bind only synthetic persisted data and the test driver.
        persisted = ['config', 'kernel/Install', 'runtime', 'app/Pay', 'assets/cache', 'app/Plugin', 'app/View/User/Theme']
        mounts = []
        for path in persisted:
            (root / path).mkdir(parents=True, exist_ok=True)
            mounts += ['-v', f'{root / path}:/var/www/html/{path}']

        def image_php(image, action):
            return command(['docker', 'run', '--rm', '--platform', 'linux/amd64', '--network', network,
                            '--entrypoint', 'php', *mounts, '-v', f'{root}/tests/upgrade:/review:ro', image,
                            '-d', 'error_reporting=22527', '/review/scenarios.php', action])

        def step(action):
            print(php('tests/upgrade/scenarios.php', action).strip(), flush=True)

        def migration(*arguments, expect=0):
            output = php('docker/migrate.php', *arguments, expect=expect)
            print('PASS migration ' + (' '.join(arguments) or 'apply') + f' (exit {expect})', flush=True)
            return output

        def sql(statement=None, dump=None):
            return command(['docker', 'exec', '-i', mysql, 'mysql', '-uroot', '-pupgrade-test-only',
                            '--default-character-set=utf8mb4'] + (['-e', statement] if statement else ['upgrade_test']), data=dump)

        def reset(dump=old_sql):
            sql('DROP DATABASE IF EXISTS upgrade_test; CREATE DATABASE upgrade_test;')
            sql(dump=dump)
            shutil.rmtree(root / 'runtime', ignore_errors=True)
            (root / 'runtime').mkdir()

        try:
            command(['docker', 'network', 'create', '--internal', network])
            command(['docker', 'run', '-d', '--name', mysql, '--network', network, '--tmpfs', '/var/lib/mysql',
                     '-e', 'MYSQL_ROOT_PASSWORD=upgrade-test-only', '-e', 'MYSQL_ROOT_HOST=%', args.mysql_image])
            for _ in range(60):
                probe = subprocess.run(['docker', 'exec', mysql, 'mysql', '-h127.0.0.1', '-uroot', '-pupgrade-test-only', '-e', 'SELECT 1'], capture_output=True)
                if probe.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Test MySQL did not start')
            if not args.images_only:
                reset()
                step('seed')
                migration('--check')
                step('assert_preflight')
                migration()
                migration()
                step('assert_migration')
                step('callbacks')
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
                    tasks = [executor.submit(php, 'tests/upgrade/scenarios.php', 'notify') for _ in range(2)]
                    for task in tasks:
                        task.result()
                step('assert_concurrent')
                step('multi')
                migration()
                migration()
                step('assert_multi')
                step('smoke')
                step('invalid_binding')
                migration(expect=1)
                step('assert_invalid_binding')

                reset()
                step('seed')
                step('partial')
                migration()
                migration()
                step('assert_migration')

                reset()
                step('seed')
                step('broken')
                migration('--check', expect=1)
                step('assert_preflight')
                migration(expect=1)
                step('repair')
                migration()
                step('assert_migration')

                reset()
                step('seed')
                step('empty_history')
                migration()
                step('assert_empty_history')
                migration()
                step('assert_empty_history')

            if args.rollback_image:
                reset()
                step('seed')
                print(image_php(args.rollback_image, 'before_upgrade').strip(), flush=True)
                print(image_php(args.rollback_image, 'trades').strip(), flush=True)
                reset()
                step('seed')
                migration()
                print(image_php(args.rollback_image, 'callbacks').strip(), flush=True)
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
                    tasks = [executor.submit(image_php, args.rollback_image, 'notify') for _ in range(2)]
                    for task in tasks:
                        task.result()
                print(image_php(args.rollback_image, 'assert_concurrent').strip(), flush=True)
                print(image_php(args.rollback_image, 'trades').strip(), flush=True)
                print(image_php(args.rollback_image, 'rollback_config').strip(), flush=True)
                migration()
                print('PASS rollback before/after migration, payments, account bindings and re-upgrade', flush=True)

            # Exercise the real shell entrypoint against an old persisted installation.
            reset()
            step('seed')
            setting = root / 'app/View/User/Theme/Cartoon/Setting.php'
            setting.write_text("<?php return ['test_setting'=>'preserve-me'];")
            settings_before = setting.read_bytes()
            config_before = (root / 'config/database.php').read_bytes()
            command(['docker', 'run', '-d', '--name', app, '--platform', 'linux/amd64', '--network', network,
                     *mounts, args.image])
            for _ in range(60):
                probe = subprocess.run(['docker', 'exec', app, 'php', '/usr/local/bin/acg-faka-healthcheck.php'], capture_output=True)
                if probe.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Entrypoint/healthcheck failed:\n' + command(['docker', 'logs', app]))
            assert setting.read_bytes() == settings_before, 'Theme settings overwritten'
            assert (root / 'config/database.php').read_bytes() == config_before, 'Database config overwritten'
            step('assert_migration')
            print(image_php(args.image, 'trades').strip(), flush=True)
            command(['docker', 'restart', app])
            for _ in range(60):
                if subprocess.run(['docker', 'exec', app, 'php', '/usr/local/bin/acg-faka-healthcheck.php'], capture_output=True).returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError('Restart healthcheck failed')
            step('assert_migration')
            command(['docker', 'rm', '-f', app])
            print('PASS container startup, restart, healthcheck and persisted configuration', flush=True)
            if args.rollback_image:
                command(['docker', 'run', '-d', '--name', app, '--platform', 'linux/amd64', '--network', network,
                         *mounts, args.rollback_image])
                for _ in range(60):
                    probe = subprocess.run(['docker', 'exec', app, 'php', '/usr/local/bin/acg-faka-rollback-healthcheck.php'], capture_output=True)
                    if probe.returncode == 0:
                        break
                    time.sleep(1)
                else:
                    raise RuntimeError('Rollback startup failed:\n' + command(['docker', 'logs', app]))
                assert setting.read_bytes() == settings_before, 'Rollback overwrote theme settings'
                assert (root / 'config/database.php').read_bytes() == config_before, 'Rollback overwrote database config'
                print(image_php(args.rollback_image, 'trades').strip(), flush=True)
                command(['docker', 'rm', '-f', app])
                print('PASS rollback startup, healthcheck and preserved configuration', flush=True)
            reset(new_sql)
            print('PASS fresh installation SQL', flush=True)
            print('All upgrade regression checks passed.', flush=True)
        finally:
            for name in (app, mysql):
                subprocess.run(['docker', 'rm', '-f', name], capture_output=True)
            subprocess.run(['docker', 'network', 'rm', network], capture_output=True)


if __name__ == '__main__':
    main()
