<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\ClienteJuridico;
use App\Models\ClienteNatural;
use App\Models\Sucursal;
use App\Models\Empresa;
use App\Models\Departamento;
use App\Models\DetalleVenta;
use App\Models\Producto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class VentaController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $ventas = null;
        if (Auth::user()->usuario == 'admin') {
            $ventas = Venta::orderBy('fecha', 'desc')
                ->get();
        } else {
            $ventas = Venta::where('idEmpleado', Auth::user()->idEmpleado)
                ->where('idSucursal', Auth::user()->empleado->departamento->idSucursal)
                ->orderBy('fecha', 'desc')
                ->get();
        }
        return view('gestion-comercial.ventas.index')->with('ventas', $ventas);
    }

    public function store(Request $request)
    {
        // Validaciones
        $request->validate([
            'idCliente' => [
                'required',
                function ($attribute, $value, $fail) {
                    $existsJuridico = DB::table('cliente_juridico')->where('idClienteJuridico', $value)->exists();
                    $existsNatural = DB::table('cliente_natural')->where('idCliente_natural', $value)->exists();
                    if (!$existsJuridico && !$existsNatural) {
                        $fail('El cliente seleccionado no existe.');
                    }
                },
            ],
            'tipo' => 'required|in:Crédito,Contado',
            'meses' => 'required_if:tipo,Crédito|integer|min:1',
            'detalles' => 'required|array|min:1',
            'detalles.*.idProducto' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (!DB::table('producto')->where('idProducto', $value)->exists()) {
                        $fail("El producto seleccionado no existe.");
                    }
                },
            ],
            'detalles.*.cantidad' => 'required|integer|min:1',
            'detalles.*.precioVenta' => 'required|numeric|min:0.01',
        ], [
            'detalles.required' => 'No ha seleccionado ningún producto.',
            'idCliente.required' => 'No ha seleccionado ningún cliente.',
        ]);

        DB::beginTransaction();

        try {
            // Recalcular totales desde el arreglo de detalles
            $subtotal = 0;
            $detalles = $request->detalles;

            foreach ($detalles as &$detalle) {
                $producto = DB::table('producto')->where('idProducto', $detalle['idProducto'])->first();

                // Validar stock
                if ($detalle['cantidad'] > $producto->StockTotal) {
                    return response()->json([
                        'errors' => [
                            'detalles' => [
                                "La cantidad solicitada para el producto {$detalle['idProducto']} excede el stock disponible."
                            ]
                        ]
                    ], 422);
                }

                // Actualizar stock del producto
                DB::table('producto')
                    ->where('idProducto', $detalle['idProducto'])
                    ->update(['StockTotal' => $producto->StockTotal - $detalle['cantidad']]);

                // Calcular subtotal del detalle
                $detalle['subtotal'] = $detalle['cantidad'] * $detalle['precioVenta'];
                $subtotal += $detalle['subtotal'];

                // Generar un ID único para el detalle
                $detalle['idDetalleVenta'] = uniqid();
            }

            $iva = $subtotal * 0.14;
            $total = $subtotal + $iva;

            // Insertar en la tabla `venta`
            $idVenta = $this->generarId();
            DB::table('venta')->insert([
                'idVenta' => $idVenta,
                'fecha' => now(),
                'tipo' => $request->tipo === 'Crédito' ? 1 : 0,
                'meses' => $request->tipo === 'Crédito' ? $request->meses : null,
                'SaldoCapital' => $subtotal,
                'iva' => $iva,
                'total' => $total,
                'idEmpleado' => Auth::user()->idEmpleado,
                'idCliente_juridico' => DB::table('cliente_juridico')->where('idClienteJuridico', $request->idCliente)->exists() ? $request->idCliente : null,
                'idCliente_natural' => DB::table('cliente_natural')->where('idCliente_natural', $request->idCliente)->exists() ? $request->idCliente : null,
                'estado' => 1,
            ]);

            // Crear un array para almacenar los detalles
            $detallesData = [];

            foreach ($detalles as $i => $detalle) {
                $detallesData[] = [
                    'idDetalleVenta' => $this->generarDetalleId($i),
                    'cantidad' => $detalle['cantidad'],
                    'subtotal' => $detalle['subtotal'],
                    'idProducto' => $detalle['idProducto'],
                    'idventa' => $idVenta,
                ];
            }

            // Insertar todos los detalles de una vez
            DB::table('detalle_venta')->insert($detallesData);

            DB::commit();
            $alert = [
                'type' => 'success',
                'message' => 'Operación exitosa.',
            ];
            return response()->json($alert);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Ocurrió un error al procesar la venta.', 'details' => $e->getMessage()], 500);
        }
    }


    public function show($id)
    {
        $venta = Venta::find($id);
        return view('gestion-comercial.ventas.detalle.index')->with('venta', $venta);
    }

    public function edit($id)
    {
        $venta = Venta::find($id);
        return response()->json($venta);
    }

    public function update(Request $request, $id)
    {

        // Validar la solicitud
        $request->validate([
            'imagen' => 'image|max:3000',
            'nombre' => 'required|min:3|unique:venta,nombre,' . $id . ',idVenta',
            'categoria' => 'required',
            'descripcion' => 'required',
        ], [
            'nombre.unique' => 'Este venta ya ha sido ingresado.',
        ]);



        $venta = Venta::find($id);
        $venta->nombre = $request->post('nombre');
        $venta->idCategoria = $request->post('categoria');
        $venta->descripcion = $request->post('descripcion');
        if ($request->hasFile('imagen')) {
            //primero eliminamos la anterior imagen
            $filePath = public_path('/assets/img/ventas/' . $venta->imagen);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            //se procede con el nuevo guardado
            $imagen = $request->file('imagen');
            $nombreVentaFormateado = str_replace(' ', '_', $venta->nombre);
            $nombreImagen = $venta->idVenta . '_' . $nombreVentaFormateado . '.' . $imagen->getClientOriginalExtension();
            $rutaImagen = public_path('/assets/img/ventas'); // Ruta donde deseas guardar la imagen
            $imagen->move($rutaImagen, $nombreImagen);
            $venta->imagen = $nombreImagen;
        }
        $venta->save();


        $alert = array(
            'type' => 'success',
            'message' => 'Operación exitosa.',
        );
        return response()->json($alert);
    }

    public function destroy($id)
    {
        $venta = Venta::find($id);
        if ($venta->bienes === null) {
            $venta->delete();
            if (file_exists(public_path('/assets/img/ventas/' . $venta->imagen))) {
                unlink(public_path('/assets/img/ventas/' . $venta->imagen)); // Eliminar imagen
            }
            $alert = array(
                'type' => 'success',
                'message' => 'El registro se ha eliminado exitosamente'
            );
        } else {
            $alert = array(
                'type' => 'error',
                'message' => 'No se puede eliminar el registro porque tiene datos asociados'
            );
        }

        return response()->json($alert);
    }

    public function baja($id)
    {
        $venta = Venta::find($id);
        $venta->estado = 0;
        $venta->save();

        $alert = array(
            'type' => 'success',
            'message' => 'El registro se ha deshabilitado exitosamente'
        );
        return response()->json($alert);
    }

    public function alta($id)
    {
        $venta = Venta::find($id);
        $venta->estado = 1;
        $venta->save();

        $alert = array(
            'type' => 'success',
            'message' => 'El registro se ha restaurado exitosamente'
        );
        return response()->json($alert);
    }

    public function generarId()
    {
        // Obtener el último registro de la tabla "venta"
        $ultimoVenta = Venta::latest('idVenta')->first();

        if (!$ultimoVenta) {
            // Si no hay registros previos, comenzar desde VT0001
            $nuevoId = 'VT0001';
        } else {
            // Obtener el número del último idVenta
            $ultimoNumero = intval(substr($ultimoVenta->idVenta, 2));

            // Incrementar el número para el nuevo registro
            $nuevoNumero = $ultimoNumero + 1;

            // Formatear el nuevo idVenta con ceros a la izquierda
            $nuevoId = 'VT' . str_pad($nuevoNumero, 4, '0', STR_PAD_LEFT);
        }

        return $nuevoId;
    }

    public function generarDetalleId($i)
    {
        // Obtener el último registro de la tabla "DetalleVenta"
        $ultimoDetalle = DetalleVenta::latest('idDetalleVenta')->first();

        if (!$ultimoDetalle) {
            // Si no hay registros previos, comenzar desde DV0001
            $nuevoId = 'DV0001';
        } else {
            // Obtener el número del último idDetalleVenta
            $ultimoNumero = intval(substr($ultimoDetalle->idventa, 2));

            // Incrementar el número para el nuevo registro
            $nuevoNumero = $ultimoNumero + 1 + $i;

            // Formatear el nuevo idVenta con ceros a la izquierda
            $nuevoId = 'DV' . str_pad($nuevoNumero, 4, '0', STR_PAD_LEFT);
        }

        return $nuevoId;
    }

    public function getVentas($tipoVenta, $tipoCliente)
    {
        // Listas de valores válidos
        $validTipoVenta = ['v-todas', 'v-contado', 'v-credito'];
        $validTipoCliente = ['c-todos', 'c-natural', 'c-juridico'];

        // Validar los parámetros
        if (!in_array($tipoVenta, $validTipoVenta) || !in_array($tipoCliente, $validTipoCliente)) {
            return response()->json(['error' => 'Parámetros inválidos. Por favor, verifica los valores enviados.'], 400);
        }

        // Construcción de la consulta base
        $ventasQuery = Venta::with(['cliente_juridico', 'cliente_natural'])
            ->orderBy('fecha', 'desc');

        // Verifica el rol del usuario
        if (Auth::user()->usuario !== 'admin') {
            $ventasQuery->where('idEmpleado', Auth::user()->idEmpleado)
                ->where('idSucursal', Auth::user()->empleado->departamento->idSucursal);
        }

        // Filtro por tipo de venta (Contado o Crédito)
        if ($tipoVenta !== 'v-todas') {
            $ventasQuery->where('tipo', $tipoVenta === 'v-contado' ? 0 : 1); // 0=Contado, 1=Crédito
        }

        // Filtro por tipo de cliente (Natural o Jurídico)
        if ($tipoCliente !== 'c-todos') {
            $ventasQuery->whereHas('cliente_' . ($tipoCliente === 'c-natural' ? 'natural' : 'juridico'));
        }

        // Ejecuta la consulta y retorna el resultado
        return response()->json($ventasQuery->get());
    }

    public function getClientes($query = null)
    {
        // Construir la consulta base para clientes naturales
        $clientesNaturales = ClienteNatural::select([
            'idCliente_natural as idCliente',
            DB::raw("CONCAT(nombres, ' ', apellidos) as cliente"),
            'estado',
            DB::raw('0 as tipo') // Tipo 0 para clientes naturales
        ])
            ->where('estado', 1);

        // Construir la consulta base para clientes jurídicos
        $clientesJuridicos = ClienteJuridico::select([
            'idClienteJuridico as idCliente',
            'nombre_empresa as cliente',
            'estado',
            DB::raw('1 as tipo') // Tipo 1 para clientes jurídicos
        ])
            ->where('estado', 1);

        // Si hay un término de búsqueda, aplicamos los filtros en ambas tablas
        if ($query) {
            $firstWord = strtok($query, ' '); // Toma la primera palabra separada por espacio
            $clientesNaturales->where(function ($queryBuilder) use ($query, $firstWord) {
                $queryBuilder->where('nombres', 'like', '%' . $query . '%')
                    ->orWhere('apellidos', 'like', '%' . $query . '%')
                    ->orWhere('idCliente_natural', 'like', '%' . $query . '%') // Filtra por ID de cliente natural
                    ->orWhere('idCliente_natural', 'like', '%' . $firstWord . '%'); // Filtra por ID de cliente natural
            });

            $clientesJuridicos->where(function ($queryBuilder) use ($query, $firstWord) {
                $queryBuilder->where('nombre_empresa', 'like', '%' . $query . '%')
                    ->orWhere('idClienteJuridico', 'like', '%' . $query . '%') // Filtra por ID de cliente jurídico
                    ->orWhere('idClienteJuridico', 'like', '%' . $firstWord . '%');
            });
        }

        // Combina las dos consultas usando un `union` optimizado
        $clientes = $clientesNaturales->unionAll($clientesJuridicos)->orderBy('cliente', 'asc')->get(); // `unionAll` puede ser más eficiente que `union`

        // Devuelve el resultado como JSON
        return response()->json($clientes);
    }

    public function getProductos($query = null)
    {

        //Construir la consulta base para clientes naturales
        $productos = Producto::select([
            'idProducto',
            'nombre',
            'estado',
            DB::raw('(
                    (SELECT IFNULL(SUM(dc.cantidad), 0) FROM detalle_compra dc WHERE dc.idProducto = producto.idProducto) - 
                     (SELECT IFNULL(SUM(dv.cantidad), 0) FROM detalle_venta dv WHERE dv.idProducto = producto.idProducto)
                     ) AS stockTotal'),
            DB::raw('ROUND((
                        (SELECT SUM(dc.precio * dc.cantidad) FROM detalle_compra dc WHERE dc.idProducto = producto.idProducto) /
                        (SELECT IFNULL(SUM(dc.cantidad), 1) FROM detalle_compra dc WHERE dc.idProducto = producto.idProducto)
                    ) * 1.10, 2) AS precioVenta')
        ])
            ->where('estado', 1) // Solo productos activos
            ->having('stockTotal', '>', 0); // Filtra productos con stock > 0

        // Si hay un término de búsqueda, aplicamos los filtros en ambas tablas
        if ($query) {
            // Extraer la primera palabra del query
            $firstWord = strtok($query, ' '); // Toma la primera palabra separada por espacio

            $productos->where(function ($queryBuilder) use ($query, $firstWord) {
                $queryBuilder->where('nombre', 'like', '%' . $query . '%')
                    ->orWhere('idProducto', 'like', '%' . $query . '%')
                    ->orWhere('idProducto', 'like', '%' . $firstWord . '%'); // Filtra por la primera palabra
            });
        }
        $productos = $productos->get();
        // Devuelve el resultado como JSON
        return response()->json($productos);
    }

    public function getIdVenta(){
        return response()->json($this->generarId());
    }
    public function pdf(Request $request)
    {
        // Configurar los parámetros iniciales
        $idSucursal = $request->input('sucursal');
        $idDepartamento = $request->input('departamento');
        $idVenta  = $request->input('venta');
        $tipoDepreciacion = $request->input('tipo');
        $idEmpresa = $request->input('empresa');

        // Obtener el nombre de la empresa según el id proporcionado
        $empresa = Empresa::find($idEmpresa);
        $nombreEmpresa = $empresa ? $empresa->nombre : 'Nombre no encontrado';

        // Preparar la consulta SQL para llamar al procedimiento almacenado
        $results = DB::select(
            'CALL ObtenerDepreciacion(?, ?, ?, ?, ?,NULL)',
            [$tipoDepreciacion, $idSucursal, $idDepartamento, $idEmpresa, $idVenta]
        );

        // Calcular el total de ventas
        $totalVentas = count($results); // Contar el número de filas en los resultados

        // Calcular los totales para cada columna
        $totalPrecio = 0;
        $totalDepreciacion = 0;
        $totalDepreciacionAcumulada = 0;
        $totalValorEnLibros = 0;

        foreach ($results as $resultado) {
            $totalPrecio += $resultado->precio;
            $totalDepreciacion += $resultado->depreciacion;
            $totalDepreciacionAcumulada += $resultado->depreciacion_acumulada;
            $totalValorEnLibros += $resultado->valor_en_libros;
        }

        // Verificar si no se encontraron datos
        if (empty($results)) {
            return response()->json([
                'type' => 'info',
                'message' => 'No existen registros para generar el informe.',
            ]);
        } else {
            // Pasar los resultados a la vista y generar el PDF
            $pdf = Pdf::loadView(
                'ventas.pdf',
                [
                    'resultados' => $results,
                    'tipoDepreciacion' => $tipoDepreciacion,
                    'nombreEmpresa' => $nombreEmpresa, // Pasar el nombre de la empresa a la vista
                    'totalVentas' => $totalVentas,
                    'totalDepreciacion' => $totalDepreciacion,
                    'totalDepreciacionAcumulada' => $totalDepreciacionAcumulada,
                    'totalValorEnLibros' => $totalValorEnLibros,
                ]
            );

            // Convertir el PDF a base64 para enviarlo mediante JSON
            $pdfBase64 = base64_encode($pdf->output());

            return response()->json([
                'type' => 'success',
                'pdf' => $pdfBase64,
            ]);
        }
    }
}
