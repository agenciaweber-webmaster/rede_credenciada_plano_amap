<?php 
/* Template Name: rede-credenciada-template */

get_header();
?>


<link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/rede_credenciada/style.css?ver=0.0.7" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">
<!--
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>

<div id="some-wrapper">
    <button id="myButton">Add button directly to the template</button>

    <?php
        // or with the shortcode callback
        // do_shortcode not strictly required here
        //$args = array(
            // 'some' => 'parameter',
        //);
        //echo rede_page_function($args);
   ?>
</div>
-->
<div id="rede_credenciada_title">
    <div class="mkdf-title-holder mkdf-centered-type mkdf-title-va-header-bottom mkdf-has-bg-image mkdf-bg-parallax" style="height: 420px; background-image: url(&quot;https://planoamap.com.br/wp-content/uploads/revslider/h1-slider-img-11.jpg&quot;); background-position: center 32px;" data-height="320">
        <div class="mkdf-title-image">
            <img itemprop="image" src="https://planoamap.com.br/wp-content/uploads/revslider/h1-slider-img-11.jpg" alt="Image Alt">
        </div>
        <div class="mkdf-title-wrapper" style="height: 420px">
            <div class="mkdf-title-inner">
                <div class="mkdf-grid">
                    <h1 class="mkdf-page-title entry-title" style="color: #ffffff">Rede Credenciada</h1>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="busca-cep-rede-credenciada" class="busca-cep-section">
    <?php echo do_shortcode('[resales-map]'); ?>
</div>

<div id='qualificacao'>
    <table>
        <tbody>
            <tr>
                <td class="has-text-align-left" data-align="left">
                    <img src="/wp-content/uploads/2020/12/APALC.png" alt="Padrão nacional de qualidade">Padrão nacional de qualidade:<br>
                        Acreditado pelo Programa de Acreditação de Laboratórios Clínicos (PALC),certificado concedido pela Sociedade Brasileira de Patologia Clínica/Medicina Laboratorial (SBPC/ML).<br>
                    <img alt="Padrão nacional de qualidade" src="/wp-content/uploads/2020/12/ADICQ.png">Padrão nacional de qualidade:<br>
                        Acreditado pelo Sistema Nacional de Acreditação (DICQ). Certificado concedido pela Sociedade Brasileira de Análises Clínicas.<br>
                    <img alt="Padrão nacional de qualidade" src="/wp-content/uploads/2020/12/AONA.png">Padrão nacional de qualidade:<br>
                        Acreditado pela Organização Nacional de Acreditação (ONA), por meio do manual próprio com reconhecimento da Anvisa/Ministério da Saúde.<br>
                    <img alt="Padrão internacional de qualidade" src="/wp-content/uploads/2020/12/ACBA.png">Padrão internacional de qualidade:<br>
                        Acreditado pelo Consórcio Brasileiro de Acreditação (CBA), por meio do manual da Joint Commission International (JCI), acreditadora norte-americana.<br>
                    <img alt="Padrão internacional de qualidade" src="/wp-content/uploads/2020/12/AIQG.png">Padrão internacional de qualidade:<br>
                        Acreditado pelo Instituto Qualisa de Gestão (IQG), por meio do manual da Accreditation Canada, acreditadora canadense.<br>
                    <img alt="Comunicação de eventos adversos" src="/wp-content/uploads/2020/12/N.png">Comunicação de eventos adversos:<br>
                        Participação no Sistema de Notificação de Eventos Adversos (Notivisa) da Anvisa:<br>
                        Sistema eletrônico gerenciado pela Agência Nacional de Vigilância Sanitária (Anvisa) para receber notificações dos estabelecimentos de saúde. A participação no Notivisa demonstra que o hospital comunica à Anvisa os casos
                        confirmados ou suspeitos de efeitos inesperados, que podem variar de alergia a óbito, por exemplo. Problemas em relação a produtos ou aparelhos utilizados em hospitais também são comunicados.<br>
                    <img alt="Profissional com especialização" src="/wp-content/uploads/2020/12/P.png">Profissional com especialização:<br>
                        Especialização em Área Profissional da Saúde na especialidade de atuação O profissional de saúde que obtém o certificado do curso de especialização dentro de sua área de atuação, demonstra que se dedicou a um programa de
                        melhoria do seu conhecimento específico.<br>
                    <img alt="Profissional com residência" src="/wp-content/uploads/2020/12/R.png">Profissional com residência:<br>
                        Residência em saúde reconhecida pelo MEC na área de atuação do profissional.<br>
                        Residência Médica na especialidade de atuação A Residência Médica é uma pós-graduação que tem o objetivo de qualificar e especializar o médico. Caracteriza-se por treinamento, orientado por profissionais mais
                        experientes, em instituições de saúde, em período integral. • Residências em Área Profissional da Saúde e Residência Multiprofissional em Saúde na área de atuação Preparam os profissionais para atuar de forma
                        multidisciplinar. Caracteriza-se por treinamento orientado por profissionais mais experientes em instituição de saúde. São várias as profissões da área da saúde abrangidas por estes programas.<br>
                    <img alt="Título de Especialista" src="/wp-content/uploads/2020/12/E.png">Título de Especialista:<br>
                        Concedido aos profissionais de saúde que para obterem este título são submetidos a avaliações que certificam seus conhecimentos específicos em suas áreas de atuação. O título é chancelado pelas associações profissionais
                        e deve ser registrado pelo próprio conselho profissional.<br>
                    <img alt="Qualidade monitorada" src="/wp-content/uploads/2020/12/Q.png">Qualidade monitorada:<br>
                        Participação no Programa de Monitoramento da Qualidade dos Prestadores de Serviços na Saúde Suplementar (Qualiss) da ANS.<br>
                        Programa da ANS que monitora, avalia e divulga o desempenho dos prestadores de serviços hospitalares no setor de planos de saúde (saúde suplementar). A ANS divulgará, periodicamente, em seu portal eletrônico, os
                        resultados das instituições que atendem ao mínimo de qualidade esperada.
                </td>
            </tr>
        </tbody>
    </table>
</div>



<?php get_footer();