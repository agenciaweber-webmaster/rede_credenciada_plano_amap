<?php

namespace BuscaCep\Controllers;

use WP_Error;
use WP_REST_Response;
use BuscaCep\Models\Storage;

/**
 * Gerencia consultas de revendas por CEP e operações de leitura.
 */
class Consulta
{
    private $storage;
    private $resale;

    public function __construct()
    {
        $this->storage = new Storage();
        $this->resale = new Resale();
    }

    /**
     * Busca revendas próximas a um CEP (raio de 5km).
     * Aceita filtro opcional por especialidade.
     */
    public function resalesPoint($request)
    {
        $cep = preg_replace('/[^\d\-]/', '', $request['cep']);

        if (strlen($cep) !== 9) {
            return new WP_Error('Bad Request', __('CEP incorreto ou não informado', 'busca-cep'), ['status' => 400]);
        }

        $especialidade = trim($request->get_param('especialidade') ?? '');

        $result = $this->resale->getGeo($cep);

        $data = [
            'lat'            => $result->latitude ?? null,
            'lng'            => $result->longitude ?? null,
            'especialidade'  => $especialidade,
        ];

        $nearbyResales = $this->findNearbyResales($data);

        if (isset($nearbyResales->errors)) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => __('Desculpe, não localizamos revendas próximas ao CEP solicitado.', 'busca-cep'),
            ]);
        }

        $json = ['makers' => [], 'resales' => [], 'especialidades' => []];

        $especialidadesUnicas = [];

        foreach ($nearbyResales as $revenda) {
            $nome = $this->getNome($revenda);
            $esp = $revenda['especialidade'] ?? '';
            if (!empty($esp) && !in_array($esp, $especialidadesUnicas)) {
                $especialidadesUnicas[] = $esp;
            }

            $endereco = "{$revenda['rua']}, {$revenda['numero']} - {$revenda['bairro']}, {$revenda['municipio']} - {$revenda['estado']}";
            $infoWindow = "<strong>{$nome}</strong>\n" .
                (!empty($esp) ? "{$esp}\n" : '') .
                "{$revenda['rua']}, {$revenda['numero']} - {$revenda['bairro']}, {$revenda['estado']}\n" .
                ($revenda['telefone'] ?? '') . "\n" .
                ($revenda['whatsapp'] ?? '');

            $json['makers'][] = [
                $nome,
                $revenda['lat'],
                $revenda['lng'],
                $infoWindow,
            ];

            $json['resales'][] = [
                'id'            => $revenda['id'] ?? null,
                'nome'          => $nome,
                'cnpj'          => $this->formatCnpjCrm($revenda['cnpj'] ?? ''),
                'endereco'      => $endereco,
                'municipio'     => $revenda['municipio'] ?? '',
                'estado'        => $revenda['estado'] ?? '',
                'telefone'      => $revenda['telefone'] ?? '',
                'whatsapp'      => $revenda['whatsapp'] ?? '',
                'horario'       => $revenda['horario'] ?? '',
                'plano'         => $revenda['plano'] ?? '',
                'especialidade' => $esp,
                'distancia'     => isset($revenda['distancia_km']) ? number_format($revenda['distancia_km'], 1, ',', '') . ' Km' : '',
            ];
        }

        sort($especialidadesUnicas);
        $json['especialidades'] = $especialidadesUnicas;

        return $json;
    }

    /**
     * Retorna o nome da revenda (razao/fantasia unificados).
     */
    private function getNome(array $row): string
    {
        return trim($row['razao'] ?? $row['fantasia'] ?? '');
    }

    /**
     * Formata CNPJ/CRM: 14 dígitos = CNPJ com máscara, caso contrário = CRM sem máscara.
     */
    private function formatCnpjCrm(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 14) {
            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
        }
        return $value;
    }

    /**
     * Retorna o label (CNPJ ou CRM) conforme o valor.
     */
    private function getCnpjCrmLabel(string $value): string
    {
        $value = trim($value);
        if (empty($value)) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $value);
        return strlen($digits) === 14 ? 'CNPJ' : 'CRM';
    }

    /**
     * Lista todas as revendas em formato HTML para a tabela admin.
     */
    public function listAll()
    {
        $html = '';
        $rows = $this->storage->getResales('*');

        if (empty($rows)) {
            return $html;
        }

        foreach ($rows as $row) {
            $plano = $row['plano'] ?? '';
            $especialidade = $row['especialidade'] ?? '';
            $nome = $this->getNome($row);
            $cnpjCrm = $this->formatCnpjCrm($row['cnpj'] ?? '');
            $cnpjCrmLabel = $cnpjCrm ? $this->getCnpjCrmLabel($row['cnpj'] ?? '') : '';
            $cnpjCrmExib = $cnpjCrm ? ($cnpjCrmLabel ? $cnpjCrmLabel . ': ' : '') . $cnpjCrm : '';
            $whatsapp = $row['whatsapp'] ?? '';
            $horario = $row['horario'] ?? '';
            $html .= "<tr id='filter' class='active-row'>";
            $html .= "<td>" . esc_html($nome) . "</td>";
            $html .= "<td>{$plano}</td>";
            $html .= "<td>{$especialidade}</td>";
            $html .= "<td>" . esc_html($cnpjCrmExib) . "</td>";
            $html .= "<td>{$whatsapp}</td>";
            $html .= "<td>{$row['telefone']}</td>";
            $html .= "<td>{$horario}</td>";
            $html .= "<td>{$row['cep']}</td>";
            $html .= "<td>{$row['rua']}</td>";
            $html .= "<td>{$row['numero']}</td>";
            $html .= "<td>{$row['bairro']}</td>";
            $html .= "<td>{$row['municipio']}</td>";
            $html .= "<td>{$row['estado']}</td>";
            $html .= "<td>{$row['status']}</td>";
            $html .= "<td class='th-display'>";
            $html .= "<button type='button' id='{$row['id']}' class='btn-primary btn-revenda btn-edit-resale'>Editar</button>";
            $html .= "<button type='button' id='{$row['id']}' class='btn-primary btn-revenda btn-delete-resale'>Excluir</button>";
            $html .= "</td>";
            $html .= "</tr>";
        }

        return $html;
    }

    /**
     * Retorna o token de configuração do plugin.
     */
    public function getToken()
    {
        $row = $this->storage->getConfig('*', ['id' => 1]);

        return $row[0]['token'] ?? null;
    }

    /**
     * Retorna detalhes de uma revenda para edição ou exclusão.
     */
    public function getDetails($data)
    {
        switch ($data['param']) {
            case 'edit':
                $row = $this->details($data['id']);
                if ($row) {
                    $arr = (array) $row;
                    $arr['nome'] = $this->getNome($arr);
                    return (object) $arr;
                }
                return $row;

            case 'delete':
                $row = $this->details($data['id']);
                $nome = $row ? $this->getNome((array) $row) : '';
                return "<p>Deseja excluir {$nome}?</p>";
        }
    }

    /**
     * Busca detalhes de uma revenda pelo ID.
     */
    private function details($id)
    {
        $row = $this->storage->getResales('*', ['id' => $id]);

        return $row[0] ?? null;
    }

    /**
     * Calcula distância entre dois pontos geográficos (Haversine) em km.
     */
    private function distance($lat1, $lon1, $lat2, $lon2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $lon1 = deg2rad($lon1);
        $lon2 = deg2rad($lon2);

        return 6371 * acos(
            cos($lat1) * cos($lat2) * cos($lon2 - $lon1) + sin($lat1) * sin($lat2)
        );
    }

    /**
     * Busca revendas dentro de 5km do ponto informado.
     * Aplica filtro por especialidade quando informado.
     */
    private function findNearbyResales(array $data)
    {
        $resales = $this->storage->getResales('*');
        $results = [];
        $especialidade = $data['especialidade'] ?? '';

        foreach ($resales as $resale) {
            if (strtolower($resale['status'] ?? '') !== 'ativo') {
                continue;
            }
            $distance = $this->distance($data['lat'], $data['lng'], $resale['lat'], $resale['lng']);

            if ($distance <= 5) {
                if (!empty($especialidade)) {
                    $espResale = $resale['especialidade'] ?? '';
                    if (stripos($espResale, $especialidade) === false && $espResale !== $especialidade) {
                        continue;
                    }
                }
                $resale['distancia_km'] = round($distance, 1);
                $results[] = $resale;
            }
        }

        usort($results, function ($a, $b) {
            return ($a['distancia_km'] ?? 0) <=> ($b['distancia_km'] ?? 0);
        });

        if (!empty($results)) {
            return $results;
        }

        return new WP_Error('Bad Request', __('Desculpe, algo deu errado na pesquisa', 'busca-cep'), ['status' => 400]);
    }
}
