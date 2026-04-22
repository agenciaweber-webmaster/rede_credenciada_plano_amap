<?php

namespace BuscaCep\Controllers;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuscaCep\Helpers\Helper;
use BuscaCep\Models\Storage;

/**
 * Gerencia operações CRUD de revendas, importação/exportação e geolocalização.
 */
class Resale
{
    private $helper;
    private $storage;

    public function __construct()
    {
        $this->helper = new Helper();
        $this->storage = new Storage();

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
    }

    public function create(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $cep = preg_replace('/[^\d\-]/', '', $param['cep'] ?? '');

        if (strlen($cep) < 8 || strlen($cep) > 9 || !isset($param['numero'])) {
            return new WP_Error('Bad Request', __('CEP incorreto ou não informado', 'busca-cep'), ['status' => 400]);
        }

        $result = $this->getGeo($cep, $param['numero']);

        if (!empty($result->error)) {
            return new WP_Error(
                'Bad Request',
                __('Ocorreu um erro ao processar sua requisição, localidade não encontrada.', 'busca-cep'),
                ['status' => 400]
            );
        }

        $data = $this->buildResaleData($param, $result, $cep);
        $validate = $this->storage->validate($data);

        if (isset($validate->quantity)) {
            $data['id'] = $validate->id;
            $response = $this->storage->update($data);
        } else {
            $response = $this->storage->insert($data);
        }

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao armazenar os dados, tente novamente.', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Atualiza uma revenda existente.
     */
    public function update(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $cep = preg_replace('/[^\d\-]/', '', $param['cep'] ?? '');

        if (strlen($cep) < 8 || strlen($cep) > 9 || !isset($param['numero'])) {
            return new WP_Error(
                'Bad Request',
                __('CEP incorreto, não informado ou fora da base de dados', 'busca-cep'),
                ['status' => 400]
            );
        }

        $result = $this->getGeo($cep, $param['numero']);

        $data = $this->buildResaleData($param, $result, $cep);
        $data['id'] = $param['id'];

        $response = $this->storage->update($data);

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao processar, entre em contato com os desenvolvedores!', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Exclui uma revenda.
     */
    public function delete(WP_REST_Request $request)
    {
        $param = $request->get_params();
        $response = $this->storage->delete($param['id']);

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Ocorreu um erro ao processar, entre em contato com os desenvolvedores!', 'busca-cep'),
            ['status' => 400]
        );
    }

    /**
     * Salva configurações do plugin (token da API de geocoding).
     */
    public function config(WP_REST_Request $request)
    {
        $params = $request->get_params();
        $response = $this->storage->config($params['geo_token'] ?? '');

        if (!empty($response['status'])) {
            return new WP_REST_Response([
                'status'        => 'ok',
                'response'      => 'application/json',
                'body_response' => $response['message'],
            ]);
        }

        return new WP_Error(
            'Bad Request',
            __('Não foi possível armazenar os dados, tente novamente.', 'busca-cep'),
            ['status' => 400, 'response' => 'application/json', 'body_response' => $response['message']]
        );
    }

    /**
     * Importação completa em uma única requisição: parse, formata, geocodifica e insere.
     */
    public function uploadFile(WP_REST_Request $request)
    {
        $files = $request->get_file_params();
        if (empty($files)) {
            $files = isset($_FILES) ? $_FILES : [];
        }
        if (!isset($files['import']) || empty($files['import']['name'])) {
            return new WP_REST_Response(['success' => false, 'error' => __('Por favor selecione um arquivo', 'busca-cep')], 400);
        }

        if (strtolower(pathinfo($files['import']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            return new WP_REST_Response(['success' => false, 'error' => __('Formato incompatível. Use CSV.', 'busca-cep')], 400);
        }

        $handle = @fopen($files['import']['tmp_name'], 'rb');
        if ($handle === false) {
            return new WP_REST_Response(['success' => false, 'error' => __('Não foi possível ler o arquivo.', 'busca-cep')], 400);
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headerLine = fgetcsv($handle, 0, ',', '"');
        if ($headerLine === false || $headerLine === [null] || empty(array_filter($headerLine, function ($v) {
            return $v !== null && trim((string) $v) !== '';
        }))) {
            fclose($handle);
            return new WP_REST_Response(['success' => false, 'error' => 'Arquivo vazio ou sem cabeçalho.'], 400);
        }

        $header_values = [];
        foreach ($headerLine as $hv) {
            $header_values[] = strtolower(trim(preg_replace('/\s+/', '_', $this->helper->formatString($hv ?? ''))));
        }

        $header_aliases = [
            'razao_social'  => 'razao',
            'nome_fantasia' => 'fantasia',
            'localidade'    => 'municipio',
            'cidade'        => 'municipio',
            'cnpj/crm'      => 'cnpj',
        ];
        $header_values = array_map(function ($h) use ($header_aliases) {
            return $header_aliases[$h] ?? $h;
        }, $header_values);

        $toInsert = [];
        $batchKeys = [];
        $erros = 0;
        $ignorados = 0;
        $erro_geocoding = null;

        while (($cells = fgetcsv($handle, 0, ',', '"')) !== false) {
            $temConteudo = false;
            foreach ($cells as $c) {
                if ($c !== null && trim((string) $c) !== '') {
                    $temConteudo = true;
                    break;
                }
            }
            if (!$temConteudo) {
                continue;
            }

            $row = @array_combine($header_values, array_pad(array_slice($cells, 0, count($header_values)), count($header_values), ''));
            if ($row === false) {
                $erros++;
                continue;
            }

            $nome = trim($row['nome'] ?? '');
            if (!empty($nome)) {
                $row['razao'] = $nome;
                $row['fantasia'] = $nome;
            }
            $razao = trim($row['razao'] ?? $nome);
            $cep = preg_replace('/[^\d\-]/', '', $row['cep'] ?? '');
            $numero = trim((string) ($row['numero'] ?? ''));

            if (empty($razao) || empty($cep) || $numero === '') {
                $erros++;
                continue;
            }
            if (strlen($cep) > 9) {
                $erros++;
                continue;
            }

            $result = $this->getGeo($cep, $numero, $this->buildGeocodeAddressLine($row, $numero));
            if (isset($result->error) || empty($result->latitude ?? null) || empty($result->longitude ?? null)) {
                $erros++;
                if ($erro_geocoding === null && isset($result->error)) {
                    $erro_geocoding = $result->error;
                }
                continue;
            }

            $resale = $this->buildImportResaleData($row, $result, $cep, null);
            $validate = $this->storage->validate($resale);
            if (isset($validate->quantity)) {
                $resale['id'] = $validate->id;
            }

            $lat = round((float) ($resale['lat'] ?? 0), 5);
            $lng = round((float) ($resale['lng'] ?? 0), 5);
            $esp = mb_strtolower(trim((string) ($resale['especialidade'] ?? '')));
            $planoKey = Helper::normalizePlanoForDuplicate($resale['plano'] ?? '');
            $cnpjKey = Helper::normalizeCnpjCrmForDuplicate($resale['cnpj'] ?? '');
            $batchKey = $lat . '|' . $lng . '|' . $esp . '|' . $planoKey . '|' . $cnpjKey;
            if (isset($batchKeys[$batchKey])) {
                $ignorados++;
                continue;
            }
            $batchKeys[$batchKey] = true;
            $toInsert[] = $resale;
        }

        fclose($handle);

        $save = ['resales' => []];
        $update = ['resales' => []];
        foreach ($toInsert as $r) {
            if (isset($r['id'])) {
                $update['resales'][] = $r;
            } else {
                $save['resales'][] = $r;
            }
        }

        if (!empty($save['resales'])) {
            $this->storage->insert($save);
        }
        if (!empty($update['resales'])) {
            $this->storage->update($update);
        }

        $total = count($save['resales']) + count($update['resales']);

        if ($total === 0) {
            $msg = 'Nenhum registro foi importado.';
            if ($erros > 0 && $erro_geocoding) {
                $msg .= ' ' . $erro_geocoding;
            } elseif ($erros > 0) {
                $msg .= ' Verifique se as colunas nome, cep e numero estão preenchidas e se o token da API Google está configurado corretamente.';
            }
            return new WP_REST_Response([
                'success' => false,
                'error'   => $msg,
                'total'   => 0,
                'erros'   => $erros,
            ], 200);
        }

        $msg = 'Importação concluída.';
        if ($ignorados > 0) {
            $msg .= " {$ignorados} linha(s) duplicada(s) ignorada(s).";
        }
        return new WP_REST_Response([
            'success'   => true,
            'msg'       => $msg,
            'total'     => $total,
            'erros'     => $erros,
            'ignorados' => $ignorados,
        ], 200);
    }

    public function export()
    {
        $header = ['nome', 'plano', 'especialidade', 'cnpj/crm', 'whatsapp', 'telefone', 'horario', 'cep', 'rua', 'numero', 'bairro', 'municipio', 'estado', 'pais', 'status'];
        $results = $this->storage->getResales('*');
        $rows = [$header];

        foreach ($results as $r) {
            $r = (array) $r;
            $rows[] = [
                $this->getNome($r),
                $r['plano'] ?? '',
                $r['especialidade'] ?? '',
                $r['cnpj'] ?? '',
                $r['whatsapp'] ?? '',
                $r['telefone'] ?? '',
                $r['horario'] ?? '',
                $r['cep'] ?? '',
                $r['rua'] ?? '',
                $r['numero'] ?? '',
                $r['bairro'] ?? '',
                $r['municipio'] ?? '',
                $r['estado'] ?? '',
                $r['pais'] ?? '',
                $r['status'] ?? 'ativo',
            ];
        }

        $date = date('d-m-Y');
        $filename = "Rede-Credenciada-{$date}.csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"');
        }
        fclose($out);
        exit;
    }

    /**
     * Monta linha de endereço para geocode (importação) quando o CSV traz logradouro completo.
     */
    private function buildGeocodeAddressLine(array $row, string $numero): ?string
    {
        $rua = trim((string) ($row['rua'] ?? ''));
        $bairro = trim((string) ($row['bairro'] ?? ''));
        $municipio = trim((string) ($row['municipio'] ?? ''));
        $estado = trim((string) ($row['estado'] ?? ''));
        if ($rua === '' || $municipio === '' || $estado === '' || trim($numero) === '') {
            return null;
        }
        $parts = [$rua, $numero];
        if ($bairro !== '') {
            $parts[] = $bairro;
        }
        $parts[] = $municipio;
        $parts[] = $estado;
        $parts[] = 'Brazil';

        return implode(', ', $parts);
    }

    /**
     * Texto vindo do formulário ou CSV — nunca preencher com retorno do geocode.
     */
    private function userProvidedText(?string $v): ?string
    {
        $t = trim((string) ($v ?? ''));

        return $t !== '' ? $t : null;
    }

    /**
     * Obtém coordenadas geográficas de um CEP usando Google Geocoding API.
     *
     * @param string|null $structuredAddress Endereço completo (ex.: import CSV) — tentativa antes do CEP isolado.
     */
    public function getGeo($cep, $numero = null, ?string $structuredAddress = null)
    {
        $row = $this->storage->getConfig('*', ['id' => 1]);
        $key = (!empty($row) && isset($row[0]['token'])) ? $row[0]['token'] : '';

        if (empty($key)) {
            return (object) ['error' => 'Token da API Google não configurado. Configure em Rede Credenciada > Configurações.'];
        }

        $cepDigits = preg_replace('/[^\d]/', '', $cep);
        if (strlen($cepDigits) < 8) {
            $cepDigits = str_pad($cepDigits, 8, '0', STR_PAD_LEFT);
        }

        if ($structuredAddress !== null && trim($structuredAddress) !== '') {
            $query = http_build_query(['address' => trim($structuredAddress), 'key' => $key, 'region' => 'br']);
            $resAddr = $this->helper->send('https://maps.googleapis.com/maps/api/geocode/json?' . $query);
            if (($resAddr->status ?? '') === 'OK' && !empty($resAddr->results[0] ?? null)) {
                $parsedAddr = $this->parseGeoResult($resAddr, $cepDigits);
                if (empty($parsedAddr->error ?? null)) {
                    $latA = isset($parsedAddr->latitude) ? (float) $parsedAddr->latitude : null;
                    $lngA = isset($parsedAddr->longitude) ? (float) $parsedAddr->longitude : null;
                    if ($latA !== null && $lngA !== null && !Helper::isPontoCentroideBrasil($latA, $lngA)) {
                        return $parsedAddr;
                    }
                }
            }
        }

        $result = null;

        // 1. Tenta com components (mais confiável para CEP Brasil)
        $components = "country:BR|postal_code:{$cepDigits}";
        $query = http_build_query(['components' => $components, 'key' => $key]);
        $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");

        // 2. Fallback: address com CEP formatado
        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $cepFormatado = strlen($cepDigits) === 8
                ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                : $cep;
            $address = $numero ? "{$cepFormatado}, {$numero}, Brazil" : "{$cepFormatado}, Brazil";
            $query = http_build_query(['address' => $address, 'key' => $key, 'region' => 'br']);
            $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");
        }

        // 3. Fallback: apenas CEP
        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $cepFormatado = strlen($cepDigits) === 8
                ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                : $cep;
            $query = http_build_query(['address' => "{$cepFormatado}, Brazil", 'key' => $key, 'region' => 'br']);
            $result = $this->helper->send("https://maps.googleapis.com/maps/api/geocode/json?$query");
        }

        if ($result === null || !is_object($result)) {
            return (object) ['error' => 'Resposta inválida da API de geolocalização.'];
        }

        $parsed = $this->parseGeoResult($result, $cepDigits);
        if (!empty($parsed->error ?? null)) {
            return $parsed;
        }

        $lat = isset($parsed->latitude) ? (float) $parsed->latitude : null;
        $lng = isset($parsed->longitude) ? (float) $parsed->longitude : null;
        if ($lat !== null && $lng !== null && Helper::isPontoCentroideBrasil($lat, $lng)) {
            $cep8n = Helper::normalizarCep8Digitos($cepDigits);
            $ufFaixa = Helper::ufFromCepDigits8($cep8n);
            $capital = Helper::capitalPorUf($ufFaixa);
            if ($capital !== '') {
                $cepFmt = strlen($cepDigits) >= 8
                    ? substr($cepDigits, 0, 5) . '-' . substr($cepDigits, 5)
                    : $cepDigits;
                $addressRetry = "{$cepFmt}, {$capital}, {$ufFaixa}, Brazil";
                $qRetry = http_build_query(['address' => $addressRetry, 'key' => $key, 'region' => 'br']);
                $rRetry = $this->helper->send('https://maps.googleapis.com/maps/api/geocode/json?' . $qRetry);
                if (($rRetry->status ?? '') === 'OK' && !empty($rRetry->results[0])) {
                    $parsed2 = $this->parseGeoResult($rRetry, $cepDigits);
                    if (empty($parsed2->error ?? null)) {
                        $lat2 = (float) ($parsed2->latitude ?? 0);
                        $lng2 = (float) ($parsed2->longitude ?? 0);
                        if (!Helper::isPontoCentroideBrasil($lat2, $lng2)) {
                            return $parsed2;
                        }
                    }
                }
            }
        }

        return $parsed;
    }

    /**
     * Retorna o nome da revenda (razao/fantasia unificados).
     */
    private function getNome(array $row): string
    {
        return trim($row['razao'] ?? $row['fantasia'] ?? '');
    }

    /**
     * Monta array de dados de uma revenda a partir dos parâmetros e resultado de geocoding.
     */
    private function buildResaleData(array $param, object $result, string $cep): array
    {
        $nome = trim($param['nome'] ?? '');
        $razao = $param['razao'] ?? $nome;
        $fantasia = $param['fantasia'] ?? $nome;
        return [
            'razao'         => $razao ?: null,
            'fantasia'      => $fantasia ?: null,
            'plano'         => $param['plano'] ?? null,
            'especialidade' => $param['especialidade'] ?? null,
            'cnpj'          => $param['cnpj'] ?? null,
            'whatsapp'      => $param['whatsapp'] ?? null,
            'horario'       => $param['horario'] ?? null,
            'telefone'      => $param['telefone'] ?? null,
            'cep'           => $cep,
            'rua'           => $this->userProvidedText($param['rua'] ?? null),
            'numero'        => $param['numero'] ?? null,
            'bairro'        => $this->userProvidedText($param['bairro'] ?? null),
            'municipio'     => $this->userProvidedText($param['municipio'] ?? null),
            'estado'        => $this->userProvidedText($param['estado'] ?? null),
            'pais'          => $this->userProvidedText($param['pais'] ?? null),
            'lat'           => $result->latitude ?? null,
            'lng'           => $result->longitude ?? null,
            'status'        => $param['status'] ?? 'ativo',
            'date'          => date('d-m-Y'),
            'token'         => $param['token'] ?? null,
        ];
    }

    /**
     * Monta dados de revenda para importação.
     * Geocode alimenta somente lat/lng; endereço e demais colunas vêm exclusivamente da planilha.
     */
    private function buildImportResaleData(array $resale, object $result, string $cep, ?string $token): array
    {
        return [
            'razao'         => $resale['razao'] ?? null,
            'fantasia'      => $resale['fantasia'] ?? null,
            'plano'         => $resale['plano'] ?? null,
            'especialidade' => $resale['especialidade'] ?? null,
            'cnpj'          => $resale['cnpj'] ?? null,
            'whatsapp'      => $resale['whatsapp'] ?? null,
            'horario'       => $resale['horario'] ?? null,
            'telefone'      => $resale['telefone'] ?? null,
            'cep'           => $cep,
            'numero'        => $resale['numero'],
            'rua'           => $this->userProvidedText($resale['rua'] ?? null),
            'bairro'        => $this->userProvidedText($resale['bairro'] ?? null),
            'municipio'     => $this->userProvidedText($resale['municipio'] ?? null),
            'estado'        => $this->userProvidedText($resale['estado'] ?? null),
            'pais'          => $this->userProvidedText($resale['pais'] ?? null),
            'lat'       => round($result->latitude, 7),
            'lng'       => round($result->longitude, 7),
            'status'    => $resale['status'] ?? 'ativo',
            'date'      => date('d-m-Y'),
            'token'     => $token,
        ];
    }

    /**
     * Escolhe o resultado do Geocode mais compatível com o CEP (vários resultados são comuns em CEPs genéricos como 40000-000).
     */
    private function pickBestGeocodeResult(array $results, string $expectedUf, string $cepDigitsNorm): ?object
    {
        if ($results === [] || !isset($results[0])) {
            return null;
        }

        $cep8 = Helper::normalizarCep8Digitos($cepDigitsNorm);
        $wantUf = strtoupper($expectedUf);
        $cepDigitsOnly = preg_replace('/\D/', '', $cep8);

        if ($wantUf !== '') {
            foreach ($results as $r) {
                $uf = strtoupper($this->extractUfFromAddressComponents($r->address_components ?? []));
                if ($uf !== $wantUf) {
                    continue;
                }
                $pc = $this->extractPostalCodeDigits($r->address_components ?? []);
                if ($pc !== '' && $this->postalMatchesCepDigits($pc, $cepDigitsOnly)) {
                    return $r;
                }
            }
            foreach ($results as $r) {
                $uf = strtoupper($this->extractUfFromAddressComponents($r->address_components ?? []));
                if ($uf === $wantUf) {
                    return $r;
                }
            }
        }

        foreach ($results as $r) {
            $pc = $this->extractPostalCodeDigits($r->address_components ?? []);
            if ($pc !== '' && $this->postalMatchesCepDigits($pc, $cepDigitsOnly)) {
                return $r;
            }
        }

        return $results[0];
    }

    private function extractUfFromAddressComponents(array $components): string
    {
        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                if ($type === 'administrative_area_level_1') {
                    return trim((string) ($component->short_name ?? ''));
                }
            }
        }

        return '';
    }

    private function extractPostalCodeDigits(array $components): string
    {
        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                if ($type === 'postal_code') {
                    return preg_replace('/\D/', '', (string) ($component->short_name ?? ''));
                }
            }
        }

        return '';
    }

    private function postalMatchesCepDigits(string $postalDigits, string $cepDigitsOnly): bool
    {
        if ($cepDigitsOnly === '' || strlen($cepDigitsOnly) < 8) {
            return false;
        }
        if ($postalDigits === $cepDigitsOnly) {
            return true;
        }
        if (strlen($postalDigits) >= 5 && strpos($cepDigitsOnly, $postalDigits) === 0) {
            return true;
        }
        if (strlen($cepDigitsOnly) >= 5 && strpos($postalDigits, substr($cepDigitsOnly, 0, 5)) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Processa resposta da API de geocoding do Google.
     *
     * @param string $cepDigits 8 dígitos do CEP (só números) para escolher o resultado correto entre vários.
     */
    private function parseGeoResult(object $result, string $cepDigits = ''): object
    {
        $json = [];

        if ($result->status !== 'OK' && $result->status !== 'ZERO_RESULTS') {
            $status = $result->status ?? '';
            $msg = $result->error_message ?? '';
            if ($status === 'REQUEST_DENIED') {
                $json['error'] = 'Chave da API Google inválida ou não autorizada. ' . ($msg ?: 'Verifique em Rede Credenciada > Configurações > Testar token.');
            } elseif ($status === 'OVER_QUERY_LIMIT' || $status === 'OVER_DAILY_LIMIT') {
                $json['error'] = 'Limite de requisições da API Google excedido. ' . ($msg ?: 'Verifique o faturamento no Google Cloud.');
            } elseif ($status === 'INVALID_REQUEST') {
                $json['error'] = 'Endereço inválido para geolocalização.';
            } else {
                $json['error'] = 'Erro na API de geolocalização: ' . ($msg ?: $status ?: 'Erro desconhecido');
            }
            return (object) $json;
        }

        if ($result->status === 'ZERO_RESULTS' || empty($result->results[0] ?? null)) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        $results = $result->results;
        if (!is_array($results)) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        $cep8 = Helper::normalizarCep8Digitos($cepDigits);
        $expectedUf = Helper::ufFromCepDigits8($cep8);
        $best = $this->pickBestGeocodeResult($results, $expectedUf, $cepDigits);

        if ($best === null) {
            $json['error'] = 'Erro na geolocalização, ponto não válido';
            return (object) $json;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $loc = $best->geometry->location ?? null;
            error_log(sprintf(
                '[busca-cep] geocode CEP_digits=%s esperado_UF=%s escolhido_UF=%s lat=%s lng=%s n_resultados=%d',
                $cep8,
                $expectedUf,
                $this->extractUfFromAddressComponents($best->address_components ?? []),
                $loc ? (string) $loc->lat : 'n/a',
                $loc ? (string) $loc->lng : 'n/a',
                count($results)
            ));
        }

        $components = $best->address_components ?? [];

        foreach ($components as $component) {
            foreach ($component->types ?? [] as $type) {
                switch ($type) {
                    case 'postal_code':
                        $json['cep'] = $component->short_name;
                        break;
                    case 'route':
                        $json['rua'] = $component->short_name;
                        break;
                    case 'sublocality_level_1':
                        $json['bairro'] = $component->short_name;
                        break;
                    case 'administrative_area_level_2':
                        $json['municipio'] = $component->short_name;
                        break;
                    case 'administrative_area_level_1':
                        $json['estado'] = $component->short_name;
                        break;
                    case 'country':
                        $json['pais'] = $component->short_name;
                        break;
                }
            }
        }

        $location = $best->geometry->location ?? null;

        if ($location) {
            $json['latitude'] = $location->lat;
            $json['longitude'] = $location->lng;
        }

        return (object) $json;
    }
}
