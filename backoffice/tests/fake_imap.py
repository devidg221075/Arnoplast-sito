"""Server IMAP finto per le prove: legge i messaggi da una cartella (un file .eml per messaggio, UID = ordine alfabetico + 1).
Uso: python3 fake_imap.py <porta> <cartella>. Non salva nulla e registra nel file <cartella>/_log ogni comando ricevuto."""
import os, re, socketserver, sys

PORT, DIR = int(sys.argv[1]), sys.argv[2]

def messages():
    names = sorted(n for n in os.listdir(DIR) if n.endswith('.eml'))
    return {i + 1: open(os.path.join(DIR, n), 'rb').read() for i, n in enumerate(names)}

class H(socketserver.StreamRequestHandler):
    def send(self, s):
        self.wfile.write(s if isinstance(s, bytes) else s.encode())
    def handle(self):
        self.send('* OK fake imap ready\r\n')
        for raw in self.rfile:
            line = raw.decode().rstrip('\r\n')
            open(os.path.join(DIR, '_log'), 'a').write(line + '\n')
            tag, _, rest = line.partition(' ')
            cmd = rest.upper()
            msgs = messages()
            if cmd.startswith('LOGIN'):
                self.send(f'{tag} OK logged in\r\n' if '"secret"' in rest else f'{tag} NO bad credentials\r\n')
            elif cmd.startswith('EXAMINE') or cmd.startswith('SELECT'):
                self.send(f'* {len(msgs)} EXISTS\r\n* OK [UIDVALIDITY 777] ok\r\n* OK [UIDNEXT {len(msgs) + 1}] ok\r\n{tag} OK [READ-ONLY] done\r\n')
            elif cmd.startswith('UID SEARCH'):
                m = re.search(r'UID (\d+):\*', rest)
                if m:
                    lo = int(m.group(1)); ids = [i for i in msgs if i >= lo] or [max(msgs)] if msgs else []
                else:
                    ids = list(msgs)
                self.send('* SEARCH ' + ' '.join(map(str, ids)) + '\r\n' + f'{tag} OK search done\r\n')
            elif cmd.startswith('UID FETCH'):
                uid = int(re.search(r'FETCH (\d+)', rest).group(1))
                body = msgs.get(uid)
                if body is None:
                    self.send(f'{tag} NO no such message\r\n')
                elif 'RFC822.SIZE' in rest:
                    self.send(f'* {uid} FETCH (UID {uid} RFC822.SIZE {len(body)})\r\n{tag} OK done\r\n')
                else:
                    self.send(f'* {uid} FETCH (UID {uid} BODY[] {{{len(body)}}}\r\n'.encode() + body + b')\r\n' + f'{tag} OK done\r\n'.encode())
            elif cmd.startswith('LOGOUT'):
                self.send(f'* BYE\r\n{tag} OK bye\r\n'); return
            else:
                self.send(f'{tag} BAD unknown\r\n')

class S(socketserver.ThreadingTCPServer):
    allow_reuse_address = True

S(('127.0.0.1', PORT), H).serve_forever()
