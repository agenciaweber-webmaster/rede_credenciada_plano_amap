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
