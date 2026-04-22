<?php

namespace BuscaCep\Controllers;

use WP_Error;
use WP_REST_Response;
use BuscaCep\Helpers\Helper;
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

        if (!empty($result->error ?? null)) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => __('Desculpe, não localizamos revendas próximas ao CEP solicitado.', 'busca-cep'),
            ]);
        }

        $searchLat = $result->latitude ?? null;
        $searchLng = $result->longitude ?? null;
        if (!$this->isValidLatLng($searchLat, $searchLng)) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => __('Desculpe, não localizamos revendas próximas ao CEP solicitado.', 'busca-cep'),
            ]);
        }

        $cepBusca8 = Helper::normalizarCep8Digitos(preg_replace('/\D/', '', $cep));

        $cepUf = $this->normalizarUfBr($result->estado ?? '');
        if ($cepUf === '' || strlen($cepUf) !== 2) {
            $cepUf = Helper::ufFromCepDigits8($cepBusca8);
        }

        $data = [
            'lat'           => (float) $searchLat,
            'lng'           => (float) $searchLng,
            'especialidade' => $especialidade,
            'cep_uf'        => $cepUf,
            'cep_busca'     => $cepBusca8,
        ];

        $nearbyResales = $this->findNearbyResales($data);

        if (isset($nearbyResales->errors)) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => __('Desculpe, não localizamos revendas próximas ao CEP solicitado.', 'busca-cep'),
            ]);
        }

        $json = [
            'makers'        => [],
            'resales'       => [],
            'especialidades' => [],
            'planos'        => [],
            'search_lat'    => (float) $searchLat,
            'search_lng'    => (float) $searchLng,
        ];

        $especialidadesUnicas = [];
        $planosUnicos = [];

        foreach ($nearbyResales as $revenda) {
            $nome = $this->getNome($revenda);
            $esp = $revenda['especialidade'] ?? '';
            if (!empty($esp) && !in_array($esp, $especialidadesUnicas)) {
                $especialidadesUnicas[] = $esp;
            }
            $plano = trim($revenda['plano'] ?? '');
            if ($plano !== '' && !in_array($plano, $planosUnicos, true)) {
                $planosUnicos[] = $plano;
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
                'lat'           => isset($revenda['lat']) ? (float) $revenda['lat'] : null,
                'lng'           => isset($revenda['lng']) ? (float) $revenda['lng'] : null,
            ];
        }

        sort($especialidadesUnicas);
        $json['especialidades'] = $especialidadesUnicas;
        sort($planosUnicos);
        $json['planos'] = $planosUnicos;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mk = $json['makers'][0] ?? null;
            error_log(sprintf(
                '[busca-cep] consulta CEP=%s search_lat=%s search_lng=%s n_makers=%d primeiro_maker_lat=%s lng=%s',
                $cep,
                isset($json['search_lat']) ? (string) $json['search_lat'] : 'null',
                isset($json['search_lng']) ? (string) $json['search_lng'] : 'null',
                count($json['makers'] ?? []),
                is_array($mk) ? (string) ($mk[1] ?? '') : 'n/a',
                is_array($mk) ? (string) ($mk[2] ?? '') : 'n/a'
            ));
        }

        return $json;
    }

    /**
     * Retorna o nome da revenda (razao/fantasia unificados).
     */
    private function getNome(array $row): string
    {
        return trim($row['nome'] ?? $row['razao'] ?? $row['fantasia'] ?? '');
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
     * Verifica se latitude e longitude são finitas e dentro dos intervalos válidos.
     */
    private function isValidLatLng($lat, $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return false;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if (!is_finite($lat) || !is_finite($lng)) {
            return false;
        }
        if (abs($lat) < 1e-7 && abs($lng) < 1e-7) {
            return false;
        }
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return false;
        }

        return true;
    }

    /**
     * Normaliza estado brasileiro para sigla (UF). Retorna string vazia se não reconhecer.
     */
    private function normalizarUfBr(?string $estado): string
    {
        if ($estado === null || $estado === '') {
            return '';
        }
        $e = trim($estado);
        if (preg_match('/^[A-Za-z]{2}$/', $e)) {
            return strtoupper($e);
        }

        static $nomes = [
            'acre'                => 'AC',
            'alagoas'             => 'AL',
            'amapá'               => 'AP',
            'amapa'               => 'AP',
            'amazonas'            => 'AM',
            'bahia'               => 'BA',
            'ceará'               => 'CE',
            'ceara'               => 'CE',
            'distrito federal'    => 'DF',
            'espírito santo'      => 'ES',
            'espirito santo'      => 'ES',
            'goiás'               => 'GO',
            'goias'               => 'GO',
            'maranhão'            => 'MA',
            'maranhao'            => 'MA',
            'mato grosso'         => 'MT',
            'mato grosso do sul'  => 'MS',
            'minas gerais'        => 'MG',
            'pará'                => 'PA',
            'para'                => 'PA',
            'paraíba'             => 'PB',
            'paraiba'             => 'PB',
            'paraná'              => 'PR',
            'parana'              => 'PR',
            'pernambuco'          => 'PE',
            'piauí'               => 'PI',
            'piaui'               => 'PI',
            'rio de janeiro'      => 'RJ',
            'rio grande do norte' => 'RN',
            'rio grande do sul'   => 'RS',
            'rondônia'            => 'RO',
            'rondonia'            => 'RO',
            'roraima'             => 'RR',
            'santa catarina'      => 'SC',
            'são paulo'           => 'SP',
            'sao paulo'           => 'SP',
            'sergipe'             => 'SE',
            'tocantins'           => 'TO',
        ];

        $k = function_exists('mb_strtolower') ? mb_strtolower($e, 'UTF-8') : strtolower($e);

        return $nomes[$k] ?? '';
    }

    /**
     * UF do CEP (geocode) e UF cadastrada na revenda devem coincidir quando ambas são conhecidas.
     * Evita listar unidades com coordenadas inconsistentes (ex.: endereço em AL, ponto na BA).
     */
    private function revendaCompativelComUfCep(array $resale, string $cepUf): bool
    {
        if ($cepUf === '' || strlen($cepUf) !== 2) {
            return true;
        }
        $rUf = $this->normalizarUfBr($resale['estado'] ?? '');
        if ($rUf === '') {
            return true;
        }

        return $rUf === $cepUf;
    }

    /**
     * CEP da revenda e CEP buscado devem pertencer à mesma UF (faixa dos Correios).
     */
    private function revendaCompativelComFaixaCepPostal(array $resale, string $cepBusca8): bool
    {
        if ($cepBusca8 === '' || strlen($cepBusca8) !== 8) {
            return true;
        }
        $ufBusca = Helper::ufFromCepDigits8($cepBusca8);
        if ($ufBusca === '') {
            return true;
        }

        $rev8 = Helper::normalizarCep8Digitos(preg_replace('/\D/', '', (string) ($resale['cep'] ?? '')));
        if ($rev8 === '' || strlen($rev8) !== 8) {
            return true;
        }
        $ufRev = Helper::ufFromCepDigits8($rev8);
        if ($ufRev === '') {
            return true;
        }

        return $ufBusca === $ufRev;
    }

    /**
     * Calcula distância entre dois pontos geográficos (Haversine) em km.
     */
    private function distance($lat1, $lon1, $lat2, $lon2): float
    {
        $lat1 = deg2rad((float) $lat1);
        $lat2 = deg2rad((float) $lat2);
        $lon1 = deg2rad((float) $lon1);
        $lon2 = deg2rad((float) $lon2);

        $cos = cos($lat1) * cos($lat2) * cos($lon2 - $lon1) + sin($lat1) * sin($lat2);
        $cos = max(-1.0, min(1.0, $cos));

        return 6371 * acos($cos);
    }

    /**
     * Busca revendas: raio de 5 km em torno do ponto do CEP; se o CEP cadastrado (8 dígitos) for
     * o mesmo da busca, inclui sempre (CEPs genéricos como 40000-000 geocodificam longe do endereço real).
     */
    private function findNearbyResales(array $data)
    {
        $resales = $this->storage->getResales('*');
        $results = [];
        $especialidade = $data['especialidade'] ?? '';
        $cepUf = $data['cep_uf'] ?? '';
        $cepBusca8 = $data['cep_busca'] ?? '';
        $searchLat = (float) $data['lat'];
        $searchLng = (float) $data['lng'];

        foreach ($resales as $resale) {
            if (strtolower($resale['status'] ?? '') !== 'ativo') {
                continue;
            }
            if (!$this->revendaCompativelComFaixaCepPostal($resale, $cepBusca8)) {
                continue;
            }
            if (!$this->revendaCompativelComUfCep($resale, $cepUf)) {
                continue;
            }

            $revCep8 = Helper::normalizarCep8Digitos(preg_replace('/\D/', '', (string) ($resale['cep'] ?? '')));
            $cepIgualBusca = ($cepBusca8 !== '' && strlen($cepBusca8) === 8 && $revCep8 === $cepBusca8);

            $row = $resale;
            $latR = $row['lat'] ?? null;
            $lngR = $row['lng'] ?? null;

            if ($cepIgualBusca && !$this->isValidLatLng($latR, $lngR)) {
                $row['lat'] = $searchLat;
                $row['lng'] = $searchLng;
                $latR = $searchLat;
                $lngR = $searchLng;
            } elseif (!$cepIgualBusca && !$this->isValidLatLng($latR, $lngR)) {
                continue;
            }

            $distance = $this->distance($searchLat, $searchLng, $latR, $lngR);

            if (!$cepIgualBusca && $distance > 5) {
                continue;
            }

            if (!empty($especialidade)) {
                $espResale = $row['especialidade'] ?? '';
                if (stripos($espResale, $especialidade) === false && $espResale !== $especialidade) {
                    continue;
                }
            }

            $row['distancia_km'] = round($distance, 1);
            $results[] = $row;
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
