<?php
use App\Modules\VentasDiseno\Services\QuotePdfService;
$svc=app(QuotePdfService::class);
$base=function($name){return ['description'=>'C','master_client'=>['name'=>'Cl'],'sub_client'=>['business_name'=>$name,'receptor_tipo'=>'01'],'ruc'=>'1','contacts'=>[['name'=>$name,'phone'=>null,'email'=>null]],'sales_project'=>['name'=>'P'],'folio'=>'T-1','generated_at'=>'2026-10-01','owner'=>['name'=>'D','phone'=>null],'parts'=>[['name'=>'P1','subtotal'=>21,'items'=>[['reference'=>'R1','description'=>'Lamp','photo_url'=>null,'quantity'=>2,'effective_unit_price'=>10.5,'discount_percent'=>0,'line_total'=>21]]]],'subtotal'=>21.0,'discount_totals_amount'=>0.0,'itbms'=>1.47,'grand_total'=>22.47,'observations_preview'=>'','conditions_text'=>'','document_status'=>'approved'];};
foreach([30,40,50,60,70,90] as $n){ $name=substr(str_repeat('Constructora Internacional del Pacifico ',4),0,$n); file_put_contents("/tmp/nm_$n.pdf",$svc->generate($base($name),false)->output()); }
echo "ok\n";
