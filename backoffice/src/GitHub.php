<?php
declare(strict_types=1);

namespace Backoffice;

/**
 * Client minimo dell'API di GitHub per «Pubblica»: legge un file dal repository e salva più file in un solo commit
 * (blob, albero, commit, aggiornamento del ramo). Serve una chiave a permessi minimi (solo «Contents: lettura e scrittura»
 * su questo repository, ed eventualmente «Actions: lettura»), scritta soltanto in config.php sul server.
 */
final class GitHub
{
    /** @var callable(string, string, ?array): array{0:int, 1:mixed} */
    private $transport;

    /**
     * @param callable|null $transport prova: (metodo, percorso, corpo) => [stato HTTP, risposta decodificata]
     */
    public function __construct(private string $repo, private string $branch, private string $token, ?callable $transport = null)
    {
        $this->transport = $transport ?? $this->curl(...);
    }

    public static function fromConfig(): ?self
    {
        $c = App::config('github');
        if (!is_array($c) || empty($c['repo']) || empty($c['token']) || !preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', (string) $c['repo'])) {
            return null;
        }
        return new self((string) $c['repo'], (string) ($c['branch'] ?? 'main'), (string) $c['token']);
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    /** Testo di un file del repository (nel ramo di pubblicazione), o null se non esiste. */
    public function read(string $path): ?string
    {
        [$st, $r] = ($this->transport)('GET', '/repos/' . $this->repo . '/contents/' . $this->encode($path) . '?ref=' . rawurlencode($this->branch), null);
        if ($st === 404) {
            return null;
        }
        if ($st !== 200 || !is_array($r) || ($r['encoding'] ?? '') !== 'base64') {
            throw new \RuntimeException($this->fail($st, $r, 'lettura di ' . $path));
        }
        return (string) base64_decode(str_replace("\n", '', (string) $r['content']), true);
    }

    /**
     * Un solo commit con tutti i file ([percorso => contenuto]; testo o dati binari). Restituisce [sha, indirizzo del commit].
     *
     * @param array<string,string> $files
     * @return array{0:string, 1:string}
     */
    public function commit(array $files, string $message): array
    {
        if (!$files) {
            throw new \InvalidArgumentException('Nessun file da pubblicare.');
        }
        $ref = $this->ok('GET', '/repos/' . $this->repo . '/git/ref/heads/' . $this->encode($this->branch), null, 'lettura del ramo ' . $this->branch);
        $parent = (string) ($ref['object']['sha'] ?? '');
        $base = (string) ($this->ok('GET', '/repos/' . $this->repo . '/git/commits/' . $parent, null, 'lettura del commit')['tree']['sha'] ?? '');
        $tree = [];
        foreach ($files as $path => $bytes) {
            $blob = $this->ok('POST', '/repos/' . $this->repo . '/git/blobs', ['content' => base64_encode($bytes), 'encoding' => 'base64'], 'invio di ' . $path);
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => (string) $blob['sha']];
        }
        $newTree = $this->ok('POST', '/repos/' . $this->repo . '/git/trees', ['base_tree' => $base, 'tree' => $tree], 'creazione dell\'albero');
        $commit = $this->ok('POST', '/repos/' . $this->repo . '/git/commits', ['message' => $message, 'tree' => (string) $newTree['sha'], 'parents' => [$parent]], 'creazione del commit');
        $this->ok('PATCH', '/repos/' . $this->repo . '/git/refs/heads/' . $this->encode($this->branch), ['sha' => (string) $commit['sha'], 'force' => false], 'aggiornamento del ramo');
        return [(string) $commit['sha'], 'https://github.com/' . $this->repo . '/commit/' . $commit['sha']];
    }

    /** Ultima costruzione del sito (GitHub Actions): ['stato' => in_corso|ok|errore, 'url' => ...], o null se non leggibile. */
    public function lastRun(): ?array
    {
        try {
            [$st, $r] = ($this->transport)('GET', '/repos/' . $this->repo . '/actions/runs?branch=' . rawurlencode($this->branch) . '&per_page=1', null);
        } catch (\Throwable) {
            return null;
        }
        $run = $st === 200 && is_array($r) ? ($r['workflow_runs'][0] ?? null) : null;
        if (!is_array($run)) {
            return null;
        }
        $state = ($run['status'] ?? '') !== 'completed' ? 'in_corso' : (($run['conclusion'] ?? '') === 'success' ? 'ok' : 'errore');
        return ['stato' => $state, 'url' => (string) ($run['html_url'] ?? ''), 'quando' => (string) ($run['created_at'] ?? '')];
    }

    private function ok(string $method, string $path, ?array $body, string $what): array
    {
        [$st, $r] = ($this->transport)($method, $path, $body);
        if ($st < 200 || $st >= 300 || !is_array($r)) {
            throw new \RuntimeException($this->fail($st, $r, $what));
        }
        return $r;
    }

    private function fail(int $status, mixed $r, string $what): string
    {
        $why = match (true) {
            $status === 401 => 'la chiave di GitHub non è valida o è scaduta',
            $status === 403 || $status === 404 => 'la chiave non ha i permessi su questo repository (serve «Contents: lettura e scrittura»)',
            $status === 409 || $status === 422 => 'il ramo è cambiato nel frattempo, riprova',
            default => 'risposta inattesa (' . $status . ')',
        };
        return 'GitHub: ' . $why . ' — ' . $what . '.';
    }

    private function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @return array{0:int, 1:mixed} */
    private function curl(string $method, string $path, ?array $body): array
    {
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . $this->token, 'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: ARNOplast-backoffice', 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, is_string($raw) ? json_decode($raw, true) : null];
    }
}
