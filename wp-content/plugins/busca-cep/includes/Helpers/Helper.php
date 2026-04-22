<?php

namespace BuscaCep\Helpers;

/**
 * Funções utilitárias do plugin.
 */
class Helper
{
    /**
     * Normaliza plano para comparação de duplicata (importação / validate).
     */
    public static function normalizePlanoForDuplicate(?string $v): string
    {
        return mb_strtolower(trim((string) ($v ?? '')));
    }

    /**
     * Normaliza CNPJ/CRM para comparação de duplicata (14 dígitos = só números; senão texto em minúsculas).
     */
    public static function normalizeCnpjCrmForDuplicate(?string $v): string
    {
        $v = trim((string) ($v ?? ''));
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 14) {
            return $digits;
        }
        return mb_strtolower($v);
    }

    /**
     * CEP com 8 dígitos (apenas números), para faixas dos Correios.
     */
    public static function normalizarCep8Digitos(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits);
        if ($d === '') {
            return '';
        }
        if (strlen($d) >= 8) {
            return substr($d, 0, 8);
        }

        return str_pad($d, 8, '0', STR_PAD_RIGHT);
    }

    /**
     * UF a partir das faixas oficiais de CEP.
     */
    public static function ufFromCepDigits8(string $cep8): string
    {
        if (strlen($cep8) !== 8 || !ctype_digit($cep8)) {
            return '';
        }

        static $ranges = null;
        if ($ranges === null) {
            $ranges = [
                ['01000000', '19999999', 'SP'],
                ['20000000', '28999999', 'RJ'],
                ['29000000', '29999999', 'ES'],
                ['30000000', '39999999', 'MG'],
                ['40000000', '48999999', 'BA'],
                ['49000000', '49999999', 'SE'],
                ['50000000', '56999999', 'PE'],
                ['57000000', '57999999', 'AL'],
                ['58000000', '58999999', 'PB'],
                ['59000000', '59999999', 'RN'],
                ['60000000', '63999999', 'CE'],
                ['64000000', '64999999', 'PI'],
                ['65000000', '65999999', 'MA'],
                ['66000000', '68899999', 'PA'],
                ['68900000', '68999999', 'AP'],
                ['69000000', '69299999', 'AM'],
                ['69300000', '69399999', 'RR'],
                ['69400000', '69899999', 'AM'],
                ['69900000', '69999999', 'AC'],
                ['70000000', '72799999', 'DF'],
                ['72800000', '72999999', 'GO'],
                ['73000000', '73699999', 'DF'],
                ['73700000', '76799999', 'GO'],
                ['76800000', '76999999', 'RO'],
                ['77000000', '77999999', 'TO'],
                ['78000000', '78899999', 'MT'],
                ['79000000', '79999999', 'MS'],
                ['80000000', '87999999', 'PR'],
                ['88000000', '89999999', 'SC'],
                ['90000000', '99999999', 'RS'],
            ];
        }

        foreach ($ranges as $r) {
            if ($cep8 >= $r[0] && $cep8 <= $r[1]) {
                return $r[2];
            }
        }

        return '';
    }

    /**
     * O Google frequentemente devolve o centróide do Brasil para CEP genérico / consulta vaga (não é um endereço real).
     */
    public static function isPontoCentroideBrasil(float $lat, float $lng): bool
    {
        $refLat = -14.235004;
        $refLng = -51.92528;
        $dLat = abs($lat - $refLat);
        $dLng = abs($lng - $refLng);

        return $dLat < 0.02 && $dLng < 0.02;
    }

    /**
     * Capital da UF (endereço para refinar geocode quando o primeiro resultado é só o centro do país).
     */
    public static function capitalPorUf(string $uf): string
    {
        static $capitais = [
            'AC' => 'Rio Branco',
            'AL' => 'Maceió',
            'AP' => 'Macapá',
            'AM' => 'Manaus',
            'BA' => 'Salvador',
            'CE' => 'Fortaleza',
            'DF' => 'Brasília',
            'ES' => 'Vitória',
            'GO' => 'Goiânia',
            'MA' => 'São Luís',
            'MT' => 'Cuiabá',
            'MS' => 'Campo Grande',
            'MG' => 'Belo Horizonte',
            'PA' => 'Belém',
            'PB' => 'João Pessoa',
            'PR' => 'Curitiba',
            'PE' => 'Recife',
            'PI' => 'Teresina',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Natal',
            'RS' => 'Porto Alegre',
            'RO' => 'Porto Velho',
            'RR' => 'Boa Vista',
            'SC' => 'Florianópolis',
            'SP' => 'São Paulo',
            'SE' => 'Aracaju',
            'TO' => 'Palmas',
        ];

        return $capitais[strtoupper($uf)] ?? '';
    }

    /**
     * Limpa variáveis de sessão usadas na importação.
     */
    public function clearSessions(): void
    {
        $keys = ['file_xlsx', 'formatedRows', 'bad_rows', 'import', 'imported'];

        foreach ($keys as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * Remove acentos de uma string.
     */
    public function formatString(string $str): string
    {
        $accents = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a',
            'Ç' => 'C', 'ç' => 'c', 'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ñ' => 'N', 'ñ' => 'n',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ý' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
            'Ž' => 'Z', 'ž' => 'z', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z',
            'Š' => 'S', 'š' => 's', 'Ś' => 'S', 'ś' => 's',
            'Đ' => 'D', 'đ' => 'd', 'Ð' => 'D', 'ð' => 'd',
            'Ł' => 'L', 'ł' => 'l',
        ];

        return strtr($str, $accents);
    }

    /**
     * Realiza uma requisição HTTP GET e retorna o resultado como objeto.
     */
    public function send(string $url): object
    {
        $header = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => $header,
        ]);

        $result = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        if ($result === false) {
            return (object) ['status' => 'ZERO_RESULTS', 'error' => "cURL falhou: $error"];
        }

        $decoded = json_decode($result);
        return is_object($decoded) ? $decoded : (object) ['status' => 'ZERO_RESULTS', 'error' => 'Resposta inválida da API.'];
    }
}
