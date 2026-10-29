"""Create a disposable loopback-only MariaDB, test migrations/API, then stop it.
Never starts, connects to, or changes the configured live MySQL instance.
"""
from pathlib import Path
import os, socket, subprocess, tempfile, time

root = Path(__file__).resolve().parents[1]
mysql = Path(r'C:\xampp\mysql\bin')
php = Path(r'C:\xampp\php\php.exe')
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 33287))
(root/'tmp').mkdir(exist_ok=True)
directory = Path(tempfile.mkdtemp(prefix='waterbonia-timer-test-',dir=root/'tmp')).resolve()
subprocess.run([str(mysql/'mysql_install_db.exe'), '--datadir='+str(directory), '--port=33287', '--silent'], check=True, capture_output=True)
log = (directory/'test-server.log').open('w')
server = subprocess.Popen([str(mysql/'mysqld.exe'), '--no-defaults', '--datadir='+str(directory), '--port=33287', '--bind-address=127.0.0.1', '--console'], stdout=log, stderr=subprocess.STDOUT)
try:
    for attempt in range(100):
        try:
            with socket.create_connection(('127.0.0.1',33287),timeout=.2): break
        except OSError:
            if server.poll() is not None:
                log.flush(); print((directory/'test-server.log').read_text(errors='replace')[-3000:]); raise RuntimeError('Isolated test server failed; inspect '+str(directory))
            time.sleep(.1)
    else:
        log.flush(); print((directory/'test-server.log').read_text(errors='replace')[-3000:]); raise RuntimeError('Isolated test server not ready')
    for name in ('waterbonia.sql','api_migration.sql','dispensing_v2_migration.sql','execution_portal_migration.sql','timer_integration_migration.sql'):
        sql=(root/'database'/name).read_bytes()
        result=subprocess.run([str(mysql/'mysql.exe'),'--protocol=TCP','--host=127.0.0.1','--port=33287','--user=root']+([] if name=='waterbonia.sql' else ['--database=waterbonia']),input=sql,capture_output=True)
        if result.returncode: raise RuntimeError(name+': '+result.stderr.decode(errors='replace'))
        print('PASS migration:', name, flush=True)
    env=dict(os.environ,WATERBONIA_TEST_PORT='33287')
    subprocess.run([str(php),str(root/'tests/timer_api_test.php')],env=env,cwd=root,check=True)
    # Full application checks share only this disposable MySQL. A separate PHP
    # server and session directory cannot touch the live portal or its sessions.
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',33390))
    sessions=directory/'http-sessions'
    sessions.mkdir()
    http_env=dict(env,WATERBONIA_DB_PORT='33287',WATERBONIA_TEST_SESSION_PATH=str(sessions))
    with (directory/'test-http.log').open('w') as http_log:
        http_server=subprocess.Popen([str(php),'-d','session.save_path='+str(sessions),'-S','127.0.0.1:33390','-t',str(root.parent)],
            env=http_env,stdout=http_log,stderr=subprocess.STDOUT)
        try:
            for attempt in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',33390),timeout=.2): break
                except OSError:
                    if http_server.poll() is not None: raise RuntimeError('Isolated PHP server failed; inspect '+str(directory))
                    time.sleep(.1)
            else: raise RuntimeError('Isolated PHP server not ready')
            subprocess.run([str(php),str(root/'tests/accountless_pages_test.php'),'http://127.0.0.1:33390/WaterBonia'],
                env=http_env,cwd=root,check=True)
        finally:
            http_server.terminate()
            http_server.wait(timeout=10)
finally:
    subprocess.run([str(mysql/'mysqladmin.exe'),'--protocol=TCP','--host=127.0.0.1','--port=33287','--user=root','shutdown'],capture_output=True,timeout=10)
    try: server.wait(timeout=10)
    except subprocess.TimeoutExpired: server.terminate(); server.wait(timeout=10)
    log.close()
    print('Isolated server stopped. Test data retained at',directory)
