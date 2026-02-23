<?php

namespace BuscaCep\Controllers;

/**
 * Registra todos os endpoints REST API do plugin.
 */
class RestApi
{
    private $resale;
    private $consulta;

    public function __construct()
    {
        $this->resale = new Resale();
        $this->consulta = new Consulta();
    }

    /**
     * Registra as rotas REST API.
     */
    public function register()
    {
        $namespace = 'resales/v1/json';

        // CRUD de revendas
        register_rest_route($namespace, '/create', [
            'methods'  => 'POST',
            'callback' => [$this->resale, 'create'],
        ]);

        register_rest_route($namespace, '/update', [
            'methods'  => 'POST',
            'callback' => [$this->resale, 'update'],
        ]);

        register_rest_route($namespace, '/delete', [
            'methods'  => 'POST',
            'callback' => [$this->resale, 'delete'],
        ]);

        // Configurações
        register_rest_route($namespace, '/config', [
            'methods'  => 'POST',
            'callback' => [$this->resale, 'config'],
        ]);

        // Import/Export
        register_rest_route($namespace, '/upload_file', [
            'methods'  => 'POST',
            'callback' => [$this->resale, 'uploadFile'],
        ]);

        register_rest_route($namespace, '/export', [
            'methods'  => 'GET',
            'callback' => [$this->resale, 'export'],
        ]);

        // Consulta de revendas por CEP
        register_rest_route($namespace, '/consult/(?P<cep>[0-9 \-]+)', [
            'methods'  => 'GET',
            'callback' => [$this->consulta, 'resalesPoint'],
            'args'     => [
                'cep' => [
                    'default'  => '',
                    'required' => true,
                ],
                'especialidade' => [
                    'default'  => '',
                    'required' => false,
                ],
            ],
        ]);

        // Listagem
        register_rest_route($namespace, '/getall', [
            'methods'  => 'GET',
            'callback' => [$this->consulta, 'listAll'],
        ]);

        register_rest_route($namespace, '/getToken', [
            'methods'  => 'GET',
            'callback' => [$this->consulta, 'getToken'],
        ]);

        register_rest_route($namespace, '/getDetails/(?P<id>\d+)/(?P<param>[a-z]+)', [
            'methods'  => 'GET',
            'callback' => [$this->consulta, 'getDetails'],
            'args'     => [
                'id' => [
                    'type'     => 'integer',
                    'default'  => 0,
                    'required' => true,
                ],
                'param' => [
                    'required' => true,
                ],
            ],
        ]);
    }
}
