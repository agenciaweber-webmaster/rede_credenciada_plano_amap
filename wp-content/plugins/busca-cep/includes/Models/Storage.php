<?php

namespace BuscaCep\Models;

use BuscaCep\Helpers\Helper;
use Jajo\JSONDB;

/**
 * Camada de persistência de dados usando JSON como storage.
 */
class Storage
{
    private $json;
    private $db = 'resales.json';
    private $config = 'config.json';

    public function __construct()
    {
        $storage_dir = BUSCACEP_COMPONENTS_DIR . '/storage';
        $this->json = new JSONDB($storage_dir);
    }

    /**
     * Maior ID já usado nas revendas (nunca usar count(rows): após exclusões, reutiliza id existente e corrompe o JSON).
     */
    private function getMaxResaleId(): int
    {
        $rows = $this->json->select('*')->from($this->db)->get();
        $max = 0;
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max;
    }

    /**
     * Insere várias revendas em uma única leitura/gravação do JSON (importação em massa).
     *
     * @param array<int, array<string, mixed>> $resales
     */
    public function bulkInsert(array $resales): int
    {
        if (empty($resales)) {
            return 0;
        }

        $existing = $this->readResalesFile();
        $maxId = 0;
        foreach ($existing as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > $maxId) {
                $maxId = $id;
            }
        }

        $firstRow = $existing[0] ?? null;
        $inserted = 0;

        foreach ($resales as $resale) {
            $maxId++;
            $resale['id'] = $maxId;
            $resale = $this->alignResaleColumns($resale, $firstRow);
            if ($firstRow === null) {
                $firstRow = $resale;
            }
            $existing[] = $resale;
            $inserted++;
        }

        $this->writeResalesFile($existing);

        return $inserted;
    }

    /**
     * Atualiza várias revendas em uma única leitura/gravação do JSON.
     *
     * @param array<int, array<string, mixed>> $resales
     */
    public function bulkUpdate(array $resales): int
    {
        if (empty($resales)) {
            return 0;
        }

        $existing = $this->readResalesFile();
        $indexById = [];
        foreach ($existing as $i => $r) {
            $indexById[(int) ($r['id'] ?? 0)] = $i;
        }

        $updated = 0;
        foreach ($resales as $resale) {
            $id = (int) ($resale['id'] ?? 0);
            if ($id <= 0 || !isset($indexById[$id])) {
                continue;
            }
            $existing[$indexById[$id]] = array_merge($existing[$indexById[$id]], $resale);
            $updated++;
        }

        if ($updated > 0) {
            $this->writeResalesFile($existing);
        }

        return $updated;
    }

    /**
<<<<<<< HEAD
     * Exclui várias revendas em uma única leitura/gravação do JSON.
     *
     * @param array<int, int> $ids
     */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $deleteSet = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $deleteSet[$id] = true;
            }
        }

        if (empty($deleteSet)) {
            return 0;
        }

        $existing = $this->readResalesFile();
        $filtered = [];
        $deleted = 0;

        foreach ($existing as $r) {
            $id = (int) ($r['id'] ?? 0);
            if (isset($deleteSet[$id])) {
                $deleted++;
                continue;
            }
            $filtered[] = $r;
        }

        if ($deleted > 0) {
            $this->writeResalesFile($filtered);
        }

        return $deleted;
    }

    /**
=======
>>>>>>> d50e80d5170b455c3f9851edb85fa9f773d63bbb
     * @return array<int, array<string, mixed>>
     */
    private function readResalesFile(): array
    {
        $path = $this->getResalesFilePath();
        if (!is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Arquivo resales.json inválido ou corrompido.');
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeResalesFile(array $rows): void
    {
        $json = wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Falha ao serializar resales.json.');
        }

        if (file_put_contents($this->getResalesFilePath(), $json, LOCK_EX) === false) {
            throw new \RuntimeException('Falha ao gravar resales.json.');
        }
    }

    private function getResalesFilePath(): string
    {
        return BUSCACEP_COMPONENTS_DIR . '/storage/' . $this->db;
    }

    /**
     * @param array<string, mixed> $resale
     * @param array<string, mixed>|null $firstRow
     * @return array<string, mixed>
     */
    private function alignResaleColumns(array $resale, ?array $firstRow): array
    {
        if ($firstRow === null) {
            return $resale;
        }

        $aligned = [];
        foreach ($firstRow as $col => $value) {
            $aligned[$col] = array_key_exists($col, $resale) ? $resale[$col] : null;
        }
        foreach ($resale as $col => $value) {
            if (!array_key_exists($col, $aligned)) {
                $aligned[$col] = $value;
            }
        }
        $aligned['id'] = $resale['id'];

        return $aligned;
    }

    /**
     * Insere uma ou mais revendas no banco JSON.
     */
    public function insert(array $data): array
    {
        $nextId = $this->getMaxResaleId() + 1;

        if (isset($data['resales'])) {
            foreach ($data['resales'] as $resale) {
                $resale['id'] = $nextId++;
                $this->json->insert($this->db, $resale);
            }
            $query = true;
        } else {
            $data['id'] = $nextId;
            $query = $this->json->insert($this->db, $data);
        }

        return [
            'status'  => (bool) $query,
            'message' => $query
                ? __('Rede credenciada cadastrada com sucesso', 'busca-cep')
                : __('Desculpe, ocorreu um erro. Tente novamente.', 'busca-cep'),
        ];
    }

    /**
     * Atualiza uma ou mais revendas no banco JSON.
     */
    public function update($data): array
    {
        if (isset($data['resales'])) {
            foreach ($data['resales'] as $resale) {
                $this->json->update($resale)
                    ->from($this->db)
                    ->where(['id' => $resale['id']])
                    ->trigger();
            }
            $row = true;
        } else {
            $row = $this->json->update($data)
                ->from($this->db)
                ->where(['id' => $data['id']])
                ->trigger();
        }

        return [
            'status'  => (bool) $row,
            'message' => $row
                ? __('Rede credenciada atualizada com sucesso', 'busca-cep')
                : __('Desculpe, ocorreu um erro. Tente novamente.', 'busca-cep'),
        ];
    }

    /**
     * Exclui uma revenda pelo ID.
     */
    public function delete($id): array
    {
        $row = $this->json->delete()
            ->from($this->db)
            ->where(['id' => $id])
            ->trigger();

        return [
            'status'  => (bool) $row,
            'message' => $row
                ? __('Rede credenciada excluída com sucesso', 'busca-cep')
                : __('Desculpe, ocorreu um erro. Tente novamente.', 'busca-cep'),
        ];
    }

    /**
     * Busca revendas com filtro opcional.
     */
    public function getResales(string $fields, array $where = null): array
    {
        $query = $this->json->select($fields)->from($this->db);

        if ($where !== null) {
            $query = $query->where($where);
        }

        return $query->get();
    }

    /**
     * Busca configurações do plugin.
     */
    public function getConfig(string $fields, array $where = null): array
    {
        $query = $this->json->select($fields)->from($this->config);

        if ($where !== null) {
            $query = $query->where($where);
        }

        return $query->get();
    }

    /**
     * Verifica duplicata: lat, lng, especialidade, plano e CNPJ/CRM (normalizados).
     */
    public function validate(array $data): object
    {
        if (!isset($data['lat']) || !isset($data['lng'])) {
            return (object) [];
        }
        $lat = round((float) $data['lat'], 5);
        $lng = round((float) $data['lng'], 5);
        $especialidade = mb_strtolower(trim((string) ($data['especialidade'] ?? '')));
        $plano = Helper::normalizePlanoForDuplicate($data['plano'] ?? '');
        $cnpjKey = Helper::normalizeCnpjCrmForDuplicate($data['cnpj'] ?? '');

        $rows = $this->json->select('*')->from($this->db)->get();
        foreach ($rows as $r) {
            $rLat = round((float) ($r['lat'] ?? 0), 5);
            $rLng = round((float) ($r['lng'] ?? 0), 5);
            $rEsp = mb_strtolower(trim((string) ($r['especialidade'] ?? '')));
            $rPlano = Helper::normalizePlanoForDuplicate($r['plano'] ?? '');
            $rCnpj = Helper::normalizeCnpjCrmForDuplicate($r['cnpj'] ?? '');
            if ($rLat === $lat && $rLng === $lng && $rEsp === $especialidade
                && $rPlano === $plano && $rCnpj === $cnpjKey) {
                $r['quantity'] = 1;
                return (object) $r;
            }
        }
        return (object) [];
    }

    /**
     * Salva/atualiza o token de configuração.
     */
    public function config(string $token): array
    {
        $query = $this->json->update(['token' => $token])
            ->from($this->config)
            ->where(['id' => 1])
            ->trigger();

        return [
            'status'  => (bool) $query,
            'message' => $query
                ? __('Configuração salva com sucesso.', 'busca-cep')
                : __('Ocorreu um erro, tente novamente.', 'busca-cep'),
        ];
    }
}
