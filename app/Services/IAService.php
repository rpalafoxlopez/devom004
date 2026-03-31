<?php

namespace App\Services;

use Anthropic\Anthropic;

class IAService
{
      private $client;

      public function __construct()
      {
          $this->client = Anthropic::factory()
              ->withApiKey(config('services.anthropic.key'))
              ->make();
      }

      /**
       * Analiza un lote de registros de nómina y detecta anomalías.
       */
      public function analizarPagos(array $registros): string
      {
          $resumen = collect($registros)->map(fn($r) =>
              "{$r['nombre']} | NSS: {$r['nss']} | Monto: \${$r['monto']}"
          )->implode("\n");

          $response = $this->client->messages()->create([
              'model'      => 'claude-opus-4-6',
              'max_tokens' => 1024,
              'messages'   => [[
                  'role'    => 'user',
                  'content' => "Analiza estos registros de nómina y detecta:\n"
                             . "1. Montos en cero que podrían ser errores\n"
                             . "2. Nombres duplicados con NSS diferente\n"
                             . "3. Cualquier anomalía notable\n\n"
                             . $resumen,
              ]],
          ]);

          return $response->content[0]->text;
      }

      /**
       * Clasifica automáticamente el tipo de archivo TXT recibido.
       */
      public function clasificarArchivo(string $muestra): array
      {
          $response = $this->client->messages()->create([
              'model'      => 'claude-opus-4-6',
              'max_tokens' => 512,
              'messages'   => [[
                  'role'    => 'user',
                  'content' => "Analiza estas primeras líneas de un archivo TXT de nómina "
                             . "y responde SOLO en JSON con: tipo_archivo, separador, "
                             . "campos_detectados (array), encoding_probable.\n\n"
                             . $muestra,
              ]],
          ]);

          return json_decode($response->content[0]->text, true) ?? [];
      }
  }
