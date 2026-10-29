"""Run HTTP failure/authentication tests on a temporary loopback PHP server."""
from pathlib import Path
import os, socket, subprocess, time
root=Path(__file__).resolve().parents[1]
php=r'C:\xampp\php\php.exe'
for port in (33388,33389):
    with socket.socket() as probe: probe.bind(('127.0.0.1',port))
sessions=root/'tmp'/'http-test-sessions';sessions.mkdir(parents=True,exist_ok=True)
env=dict(os.environ,WATERBONIA_DB_PORT='33389') # Guaranteed unavailable; never uses live DB.
with (root/'tmp'/'http-test.log').open('w') as log:
    server=subprocess.Popen([php,'-d','session.save_path='+str(sessions),'-S','127.0.0.1:33388','-t',str(root.parent)],env=env,stdout=log,stderr=subprocess.STDOUT)
    try:
        for attempt in range(50):
            try:
                with socket.create_connection(('127.0.0.1',33388),timeout=.2): break
            except OSError: time.sleep(.1)
        subprocess.run([php,str(root/'tests'/'portal_http_test.php'),'http://127.0.0.1:33388/WaterBonia'],env=env,check=True)
    finally: server.terminate();server.wait(timeout=10)
