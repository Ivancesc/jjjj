<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Person;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Item;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant\Company;
use App\Models\Tenant\Warehouse;
use Illuminate\Support\Str;
use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use App\CoreFacturalo\Requests\Inputs\Common\EstablishmentInput;
use App\CoreFacturalo\Template;
use Mpdf\Mpdf;
use Mpdf\HTMLParserMode;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Exception;
use Illuminate\Support\Facades\Mail;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseQuotation;
use Modules\Purchase\Http\Resources\PurchaseOrderCollection;
use Modules\Purchase\Http\Resources\PurchaseOrderResource;
use Modules\Purchase\Mail\PurchaseOrderEmail;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Catalogs\ChargeDiscountType;
use App\Models\Tenant\Catalogs\AffectationIgvType;
use App\Models\Tenant\Catalogs\PriceType;
use App\Models\Tenant\Catalogs\SystemIscType;
use App\Models\Tenant\Catalogs\AttributeType;
use App\Models\Tenant\PaymentMethodType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\Tenant\PurchaseOrderRequest;
use App\CoreFacturalo\Requests\Inputs\Common\PersonInput;
use Modules\Sale\Models\SaleOpportunity;
use Modules\Finance\Helpers\UploadFileHelper;
use Modules\Item\Models\Line;
use Modules\Item\Models\Family;
use Modules\Purchase\Models\PurchaseOrderState;
use Modules\Purchase\Models\PurchaseOrderType;
use Modules\Transport\Models\WorkOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use App\Models\Tenant\ListPriceItem;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User;
use Barryvdh\DomPDF\Facade as PDF;


class PurchaseOrderController extends Controller
{

    use StorageDocument;

    protected $purchase_order;
    protected $company;

    public function index()
    {
        return view('purchase::purchase-orders.index');
    }


    public function create($id = null)
    {
        //dd($id);
        $sale_opportunity = null;
        return view('purchase::purchase-orders.form', compact('id', 'sale_opportunity'));
    }

    public function generate($id)
    {
        $purchase_quotation = PurchaseQuotation::with(['items'])->findOrFail($id);

        return view('purchase::purchase-orders.generate', compact('purchase_quotation'));
    }

    public function generateFromSaleOpportunity($id)
    {
        $sale_opportunity = SaleOpportunity::with(['items'])->findOrFail($id);
        $id = null;

        return view('purchase::purchase-orders.form', compact('id', 'sale_opportunity'));
    }

    public function columns()
    {
        //$suppliers = $this->table('suppliers');
        //dd($date_of_issue);
        //return compact('suppliers');
        return [
            'date_of_issue' =>  'Fecha de emision'
        ];
    }
    public function filter()
    {
        $suppliers = $this->table('suppliers');
        $purchase_order_states = PurchaseOrderState::get();
        //dd($date_of_issue);
        return compact('suppliers', 'purchase_order_states');
    }

    public function records(Request $request)
    {

        $wheres = [];
        if ($request->column) :
            $array1 = [$request->column, 'like', "%{$request->value}%"];
            array_push($wheres, $array1);
        endif;
        if ($request->supplier_id) :
            $array1 = ['supplier_id', $request->supplier_id];
            array_push($wheres, $array1);
        endif;
        if ($request->purchase_order_states_id) :
            $array1 = ['purchase_order_state_id', $request->purchase_order_states_id];
            array_push($wheres, $array1);
        endif;
        //dd($wheres);
        //dd($wheres);
        if ($request->date_of_issue &&  $request->date_of_due) {
            $records = PurchaseOrder::where($wheres)->whereBetween('date_of_issue', [$request->date_of_issue, $request->date_of_due])
                /*cuando se coloca el whereTypeUser valida por el usuario que creo cada orden de compra */
                /**->whereTypeUser()*/
                ->latest();
        } else {
            $records = PurchaseOrder::where($wheres)
                /*cuando se coloca el whereTypeUser valida por el usuario que creo cada orden de compra */
                /**->whereTypeUser()*/
                ->latest();
        }


        return new PurchaseOrderCollection($records->paginate(config('tenant.items_per_page')));
    }


    public function tables()
    {

        $suppliers = $this->table('suppliers');
        // $establishments =
        $comboestablishment = Establishment::all();
        //obtiene el id 1 depende del usuario.
        $establishment = Establishment::where('id', auth()->user()->establishment_id)->first();
        $currency_types = CurrencyType::whereActive()->get();
        $company = Company::active();
        $payment_method_types = PaymentMethodType::all();

        $lines = Line::get();
        $families = Family::get();
        $purchase_order_states = PurchaseOrderState::get();
        $purchase_order_types = PurchaseOrderType::get();
        $work_orders = WorkOrder::where('work_order_state_id', '01')->get(['id', 'prefix', 'number']);

        return compact(
            'suppliers',
            'establishment',
            'company',
            'currency_types',
            'payment_method_types',
            'lines',
            'families',
            'purchase_order_states',
            'purchase_order_types',
            'work_orders',
            'comboestablishment'
        );
    }


    public function item_tables()
    {

        $items = $this->table('items');
        $affectation_igv_types = AffectationIgvType::whereActive()->get();
        $system_isc_types = SystemIscType::whereActive()->get();
        $price_types = PriceType::whereActive()->get();
        $discount_types = ChargeDiscountType::whereType('discount')->whereLevel('item')->get();
        $charge_types = ChargeDiscountType::whereType('charge')->whereLevel('item')->get();
        $attribute_types = AttributeType::whereActive()->orderByDescription()->get();
        $warehouses = Warehouse::all();

        return compact(
            'items',
            'categories',
            'affectation_igv_types',
            'system_isc_types',
            'price_types',
            'discount_types',
            'charge_types',
            'attribute_types',
            'warehouses'
        );
    }

    public function previousCost($item_id)
    {

        $purchase_order_item = PurchaseOrderItem::where('item_id', $item_id)->latest('id')->first();

        if ($purchase_order_item) {
            return [
                'previous_cost' => $purchase_order_item->unit_price,
                'previous_currency_type_id' => $purchase_order_item->purchase_order->currency_type_id,
            ];
        }

        return [
            'previous_cost' => 0,
            'previous_currency_type_id' => null,
        ];
    }

    public function record($id)
    {
        $record = new PurchaseOrderResource(PurchaseOrder::findOrFail($id));

        return $record;
    }


    public function getFullDescription($row)
    {

        $desc = ($row->internal_id) ? $row->internal_id . ' - ' . $row->description : $row->description;
        $category = ($row->category) ? " - {$row->category->name}" : "";
        $brand = ($row->brand) ? " - {$row->brand->name}" : "";

        $desc = "{$desc} {$category} {$brand}";
        //dd($desc);
        return $desc;
    }
    public function precio_list($row)
    {

        $list_price = ListPriceItem::where('item_id', $row->id)->first();

        return  $list_price['price_fob'];
    }


    public function store(PurchaseOrderRequest $request)
    {


        DB::connection('tenant')->transaction(function () use ($request) {

            $data = $this->mergeData($request);

            $id = $request->input('id');

            $this->purchase_order =  PurchaseOrder::updateOrCreate(['id' => $id], $data);

            $this->purchase_order->items()->delete();

            foreach ($data['items'] as $row) {
                $this->purchase_order->items()->create($row);
            }

            $temp_path = $request->input('attached_temp_path');

            if ($temp_path) {

                $datenow = date('YmdHis');
                $file_name_old = $request->input('attached');
                $file_name_old_array = explode('.', $file_name_old);
                $file_name = Str::slug($this->purchase_order->id) . '-' . $datenow . '.' . $file_name_old_array[1];
                $file_content = file_get_contents($temp_path);
                Storage::disk('tenant')->put('purchase_order_attached' . DIRECTORY_SEPARATOR . $file_name, $file_content);
                $this->purchase_order->upload_filename = $file_name;
                $this->purchase_order->save();
            }

            $this->setFilename();
            $this->createPdf($this->purchase_order, "a4", $this->purchase_order->filename);
            //$this->email($this->purchase_order);
        });

        return [
            'success' => true,
            'data' => [
                'id' => $this->purchase_order->id,
                'number_full' => $this->purchase_order->number_full,
            ],
        ];
    }


    public function mergeData($inputs)
    {

        $this->company = Company::active();

        $values = [
            'user_id' => auth()->id(),
            'supplier' => PersonInput::set($inputs['supplier_id']),
            'external_id' => Str::uuid()->toString(),
            'establishment' => EstablishmentInput::set($inputs['establishment_id']),
            'soap_type_id' => $this->company->soap_type_id,
            'state_type_id' => '01'
        ];

        $inputs->merge($values);

        return $inputs->all();
    }



    private function setFilename()
    {

        $name = [$this->purchase_order->prefix, $this->purchase_order->id, date('Ymd')];
        $this->purchase_order->filename = join('-', $name);
        $this->purchase_order->save();
    }


    public function table($table)
    {
        switch ($table) {
            case 'suppliers':

                $suppliers = Person::whereType('suppliers')->orderBy('name')->get()->transform(function ($row) {
                    return [
                        'id' => $row->id,
                        'description' => $row->number . ' - ' . $row->name,
                        'name' => $row->name,
                        'number' => $row->number,
                        'email' => $row->email,
                        'identity_document_type_id' => $row->identity_document_type_id,
                        'identity_document_type_code' => $row->identity_document_type->code
                    ];
                });
                return $suppliers;

                break;

            case 'items':

                $warehouse = Warehouse::where('establishment_id', auth()->user()->establishment_id)->first();

                $items = Item::orderBy('description')->whereNotIsSet()
                    ->get()->transform(function ($row) {
                        $price_list = $this->precio_list($row);
                        //dd($price_list);
                        $full_description = $this->getFullDescription($row);
                        return [
                            'id' => $row->id,
                            'full_description' => $full_description,
                            'description' => $row->description,
                            'currency_type_id' => $row->currency_type_id,
                            'currency_type_symbol' => $row->currency_type->symbol,
                            'sale_unit_price' => $row->sale_unit_price,
                            'purchase_unit_price' => $row->purchase_unit_price,
                            'unit_type_id' => $row->unit_type_id,
                            'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                            'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                            'has_perception' => (bool) $row->has_perception,
                            'percentage_perception' => $row->percentage_perception,
                            'fob' =>  $price_list,
                            'item_unit_types' => collect($row->item_unit_types)->transform(function ($row) {
                                return [
                                    'id' => $row->id,
                                    'description' => "{$row->description}",
                                    'item_id' => $row->item_id,
                                    'unit_type_id' => $row->unit_type_id,
                                    'quantity_unit' => $row->quantity_unit,
                                    'price1' => $row->price1,
                                    'price2' => $row->price2,
                                    'price3' => $row->price3,
                                    'price_default' => $row->price_default,
                                ];
                            }),
                            'series_enabled' => (bool) $row->series_enabled,
                        ];
                    });
                return $items;

                break;
            default:
                return [];

                break;
        }
    }


    public function download($external_id, $format = "a4")
    {

        $purchase_order = PurchaseOrder::where('external_id', $external_id)->first();

        if (!$purchase_order) throw new Exception("El código {$external_id} es inválido, no se encontro la cotización de compra relacionada");

        $this->reloadPDF($purchase_order, $format, $purchase_order->filename);

        return $this->downloadStorage($purchase_order->filename, 'purchase_order');
    }

    public function downloadAttached($external_id)
    {

        $purchase_order = PurchaseOrder::where('external_id', $external_id)->first();

        if (!$purchase_order) throw new Exception("El código {$external_id} es inválido, no se encontro la orden de compra relacionada");

        return Storage::disk('tenant')->download('purchase_order_attached' . DIRECTORY_SEPARATOR . $purchase_order->upload_filename);
    }

    public function toPrint($external_id, $format)
    {

        $purchase_order = PurchaseOrder::where('external_id', $external_id)->first();

        if (!$purchase_order) throw new Exception("El código {$external_id} es inválido, no se encontro la cotización de compra relacionada");

        $this->reloadPDF($purchase_order, $format, $purchase_order->filename);
        $temp = tempnam(sys_get_temp_dir(), 'purchase_order');

        file_put_contents($temp, $this->getStorage($purchase_order->filename, 'purchase_order'));

        return response()->file($temp);
    }


    private function reloadPDF($purchase_order, $format, $filename)
    {
        $this->createPdf($purchase_order, $format, $filename);
    }


    public function createPdf($purchase_order = null, $format_pdf = null, $filename = null)
    {

        $template = new Template();
        $pdf = new Mpdf();

        $document = ($purchase_order != null) ? $purchase_order : $this->purchase_order;
        $company = ($this->company != null) ? $this->company : Company::active();
        $filename = ($filename != null) ? $filename : $this->purchase_order->filename;

        $base_template = config('tenant.pdf_template');

        $html = $template->pdf($base_template, "purchase_order", $company, $document, $format_pdf);

        $pdf_font_regular = 'oc_font'; // config('tenant.pdf_name_regular');
        $pdf_font_bold = 'oc_font'; //config('tenant.pdf_name_bold');
        // throw new Exception("");

        if (true) {
            $defaultConfig = (new ConfigVariables())->getDefaults();
            $fontDirs = $defaultConfig['fontDir'];

            $defaultFontConfig = (new FontVariables())->getDefaults();
            $fontData = $defaultFontConfig['fontdata'];

            $pdf = new Mpdf([
                'fontDir' => array_merge($fontDirs, [
                    app_path('CoreFacturalo' . DIRECTORY_SEPARATOR . 'Templates' .
                        DIRECTORY_SEPARATOR . 'pdf' .
                        DIRECTORY_SEPARATOR . $base_template .
                        DIRECTORY_SEPARATOR . 'font')
                ]),
                'fontdata' => $fontData + [
                    'frutiger' => [
                        'R' => 'oc_font.ttf',
                        'I' => 'oc_font.ttf',
                    ]
                ],
                'default_font' => 'frutiger'
            ]);
        }

        $path_css = app_path('CoreFacturalo' . DIRECTORY_SEPARATOR . 'Templates' .
            DIRECTORY_SEPARATOR . 'pdf' .
            DIRECTORY_SEPARATOR . $base_template .
            DIRECTORY_SEPARATOR . 'style.css');

        $stylesheet = file_get_contents($path_css);

        $pdf->WriteHTML($stylesheet, HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        if ($format_pdf != 'ticket') {
            if (config('tenant.pdf_template_footer')) {
                $html_footer = $template->pdfFooter($base_template);
                $pdf->SetHTMLFooter($html_footer);
            }
        }

        $this->uploadFile($filename, $pdf->output('', 'S'), 'purchase_order');
    }


    public function uploadFile($filename, $file_content, $file_type)
    {
        $this->uploadStorage($filename, $file_content, $file_type);
    }


    // public function email($purchase_order)
    // {
    //     $suppliers = $purchase_order->suppliers;
    //     // dd($suppliers);

    //     foreach ($suppliers as $supplier) {

    //         $client = Person::find($supplier->supplier_id);
    //         $supplier_email = $supplier->email;

    //         Mail::to($supplier_email)->send(new PurchaseOrderEmail($client, $purchase_order));
    //     }

    //     return [
    //         'success' => true
    //     ];
    // }

    public function uploadAttached(Request $request)
    {

        $validate_upload = UploadFileHelper::validateUploadFile($request, 'file', 'jpg,jpeg,png,gif,svg,pdf');

        if (!$validate_upload['success']) {
            return $validate_upload;
        }

        if ($request->hasFile('file')) {
            $new_request = [
                'file' => $request->file('file'),
                'type' => $request->input('type'),
            ];

            return $this->upload_attached($new_request);
        }
        return [
            'success' => false,
            'message' =>  __('app.actions.upload.error'),
        ];
    }

    function upload_attached($request)
    {
        $file = $request['file'];
        $type = $request['type'];

        $temp = tempnam(sys_get_temp_dir(), $type);
        file_put_contents($temp, file_get_contents($file));

        $mime = mime_content_type($temp);
        $data = file_get_contents($temp);

        return [
            'success' => true,
            'data' => [
                'filename' => $file->getClientOriginalName(),
                'temp_path' => $temp,
                'temp_image' => 'data:' . $mime . ';base64,' . base64_encode($data)
            ]
        ];
    }

    public function anular($id)
    {
        $obj =  PurchaseOrder::find($id);
        $obj->state_type_id = 11;
        $obj->save();
        return [
            'success' => true,
            'message' => 'Orden de compra anulada con éxito'
        ];
    }
    public function download_filters($filer1, $filer2, $filer3, $filer4, $filer5)
    {

        $wheres = [];
        $items =[];
        if ($filer1!='null') :
            //$array1 = [$filer1, 'like', "%{null}%"];
            //array_push($wheres, $array1);
        endif;

        if ($filer2 != 'null') :
            $array1 = ['supplier_id', $filer2];
            array_push($wheres, $array1);
        endif;
        if ($filer5 != 'null') :
            $array1 = ['purchase_order_state_id', '=', $filer5];
            array_push($wheres, $array1);
        endif;
        if ($filer3 != 'null' &&  $filer4 != 'null') {
            //dd('a');
            $records = PurchaseOrder::where($wheres)->whereBetween('date_of_issue', [$filer3, $filer4])
                /*cuando se coloca el whereTypeUser valida por el usuario que creo cada orden de compra */
                /**->whereTypeUser()*/
                ->get();
        } else {
            $company = Company::active();
            $records = PurchaseOrder::where($wheres)->get()->transform(function ($row) {
                $UserName = $this->getUser( $row->user_id);

                return [
                    'id' => $row->id,
                    'number' => $row->number_full,
                    'userName' => $UserName[0]['name'],
                    'date_of_issue' => $row->date_of_issue,
                    'date_of_due' => $row->date_of_due,
                    'supplier_id' => $row->supplier_id,
                    'supplier' => $row->supplier->name,
                    'currency_type' => $row->currency_type->description,
                    'work_order' => $row->work_order->id,
                    'purchase_order_state'=> $row->purchase_order_state->description,
                    'total_value'=>$row->total_value,
                    'total_igv'=>$row->total_igv,
                    'total'=>$row->total,
                    'items' => collect($row->items)->transform(function ($row) {

                        $items_des = $row->item;
                        return [
                            'item_id' => $row->item_id,
                            'unit_type_id'=>$items_des->{'unit_type_id'},
                            'internal_id' => $row->itemss->item_code,
                            'unit_price'=>$row->unit_price,
                            'quantity'=>$row->quantity,
                            'total'=>$row->total_value,
                            'description'=>$items_des->{'description'},
                        ];
                    }),
                ];
            });
            /*cuando se coloca el whereTypeUser valida por el usuario que creo cada orden de compra */
            /**->whereTypeUser()*/
        }

        $view = "purchase::purchase-orders.report.pdf";
        //dd($record);
        set_time_limit(0);

        $pdf = PDF::loadView($view, compact("records", "company"));
        $filename = "Reporte_pdf";

        return $pdf->download($filename . '.pdf');
    }
    public function getUser($id){
        $user = User::where('id',$id)->get()->transform(function ($row) {

            return [
                'name' => $row->name,
            ];

        });
        return $user;
    }
}
