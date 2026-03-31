<?php

/**
 * Busca documentos en la colección Verificacobranza que cumplan con los criterios
 * 
 * @param MongoDB\Collection $collection Instancia de la colección MongoDB
 * @param string $poliza Número de póliza a buscar
 * @param float $monto Monto a buscar
 * @param int $idEmpleado ID de empleado a buscar
 * @return array Documentos que cumplen con los criterios
 */
function buscarPorCriterios($collection, $poliza, $monto, $idEmpleado) {
    try {
        // Construir el filtro de búsqueda
        $filter = [
            'resultados.datosPago.poliza' => $poliza,
            'resultados.datosPago.monto' => $monto,
            'resultados.id_empleado' => $idEmpleado
        ];
        
        // Opciones de proyección - solo queremos _id y file_result
        $options = [
            'projection' => [
                '_id' => 1,
                'file_result' => 1,
                'layout_integracion.layoutName' => 1
            ]
        ];
        
        // Ejecutar la consulta
        $cursor = $collection->find($filter, $options);
        
        // Convertir resultados a array
        $resultados = [];
        foreach ($cursor as $documento) {
            $resultados[] = [
                '_id' => (string) $documento['_id'],
                'file_result' => $documento['file_result'],
                'layout_name' => $documento['layout_integracion']['layoutName'] ?? null
            ];
        }
        
        return $resultados;
        
    } catch (Exception $e) {
        throw new Exception("Error al buscar documentos: " . $e->getMessage());
    }
}

/**
 * Versión alternativa que busca en el contenido del JSON de file_result
 * Esta función descarga y parsea el JSON para buscar dentro de los resultados
 * 
 * @param MongoDB\Collection $collection Instancia de la colección MongoDB
 * @param string $poliza Número de póliza a buscar
 * @param float $monto Monto a buscar
 * @param int $idEmpleado ID de empleado a buscar
 * @return array Documentos que cumplen con los criterios
 */
function buscarEnFileResult($collection, $poliza, $monto, $idEmpleado) {
    try {
        // Primero obtenemos todos los documentos (o podemos filtrar por fecha si es necesario)
        $filter = []; // Puedes agregar filtros adicionales aquí
        $options = [
            'projection' => [
                '_id' => 1,
                'file_result' => 1,
                'layout_integracion.layoutName' => 1
            ]
        ];
        
        $cursor = $collection->find($filter, $options);
        
        $resultados = [];
        
        foreach ($cursor as $documento) {
            $fileResultUrl = $documento['file_result'];
            
            // Descargar el contenido del JSON
            $jsonContent = file_get_contents($fileResultUrl);
            
            if ($jsonContent === false) {
                continue; // Si no se puede descargar, saltar este documento
            }
            
            $data = json_decode($jsonContent, true);
            
            if ($data === null) {
                continue; // Si no es JSON válido, saltar
            }
            
            // Buscar dentro de los resultados
            $encontrado = false;
            
            // Verificar si existe la estructura esperada
            if (isset($data['resultados']) && is_array($data['resultados'])) {
                foreach ($data['resultados'] as $resultado) {
                    if (isset($resultado['datosPago']['poliza']) && 
                        $resultado['datosPago']['poliza'] == $poliza &&
                        isset($resultado['datosPago']['monto']) && 
                        floatval($resultado['datosPago']['monto']) == $monto &&
                        isset($resultado['id_empleado']) && 
                        $resultado['id_empleado'] == $idEmpleado) {
                        
                        $encontrado = true;
                        break;
                    }
                }
            }
            
            if ($encontrado) {
                $resultados[] = [
                    '_id' => (string) $documento['_id'],
                    'file_result' => $fileResultUrl,
                    'layout_name' => $documento['layout_integracion']['layoutName'] ?? null
                ];
            }
        }
        
        return $resultados;
        
    } catch (Exception $e) {
        throw new Exception("Error al buscar en file_result: " . $e->getMessage());
    }
}

// Ejemplo de uso:
/*
// Configuración de conexión MongoDB
require_once 'vendor/autoload.php'; // Si usas Composer

$client = new MongoDB\Client("mongodb://localhost:27017");
$collection = $client->tu_base_de_datos->Verificacobranza;

// Parámetros de búsqueda
$poliza = "3984";
$monto = 948.8;
$idEmpleado = 10082686;

// Buscar documentos
try {
    $resultados = buscarPorCriterios($collection, $poliza, $monto, $idEmpleado);
    
    if (count($resultados) > 0) {
        echo "Documentos encontrados:\n";
        foreach ($resultados as $resultado) {
            echo "ID: " . $resultado['_id'] . "\n";
            echo "Archivo: " . $resultado['file_result'] . "\n";
            echo "Layout: " . $resultado['layout_name'] . "\n";
            echo "------------------------\n";
        }
    } else {
        echo "No se encontraron documentos con los criterios especificados.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
*/