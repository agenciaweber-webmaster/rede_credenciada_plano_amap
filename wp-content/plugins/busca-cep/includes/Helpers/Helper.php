<?php

namespace BuscaCep\Helpers;

/**
 * Funções utilitárias do plugin.
 */
class Helper
{
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
