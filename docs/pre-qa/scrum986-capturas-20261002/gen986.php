<?php
use App\Modules\VentasDiseno\Services\QuotePdfService;
$svc = app(QuotePdfService::class);
function mk($parts,$itemsPer,$signed,$longObs=false,$longCond=false,$discount=0.0){
 $p=[];$n=0;
 for($a=1;$a<=$parts;$a++){ $items=[];
  for($i=1;$i<=$itemsPer;$i++){$n++; $items[]=['reference'=>"REF-$n",'description'=>"Lampara de prueba numero $n con descripcion larga para ver ajuste de linea",'photo_url'=>null,'quantity'=>2,'effective_unit_price'=>10.5,'discount_percent'=>0,'line_total'=>21];}
  $p[]=['name'=>"Partida $a",'subtotal'=>21*$itemsPer,'items'=>$items];}
 $sub=21*$itemsPer*$parts;
 return ['description'=>'Cotizacion','master_client'=>['name'=>'Cliente'],'sub_client'=>['business_name'=>'Sub SA','receptor_tipo'=>'01'],'ruc'=>'1-1','contacts'=>[['name'=>'Maria Perez','phone'=>null,'email'=>null]],'sales_project'=>['name'=>'P'],'folio'=>'T-1','generated_at'=>'2026-10-01','owner'=>['name'=>'Dis','phone'=>null],'parts'=>$p,'subtotal'=>(float)$sub,'discount_totals_amount'=>$discount,'itbms'=>round($sub*0.07,2),'grand_total'=>round($sub*1.07-$discount,2),
 'observations_preview'=>$longObs?str_repeat("Linea de observacion larga. ",60):'Fecha de entrega: Por definir',
 'conditions_text'=>$longCond?implode("\n",array_map(fn($i)=>"$i. Condicion numero $i con texto bastante largo para que ocupe varias lineas en el documento final",range(1,25))):"1. 50% abono inicial.\n2. Entregas solo en Ciudad de Panama.\n3. Cotizacion valida 15 dias.",
 'document_status'=>$signed?'approved':'draft'];}
$cases=[
 'a1item'=>[1,1,false],'b1item_firmada'=>[1,1,true],'c3items'=>[1,3,false],'d3partidas'=>[3,2,true],
 'e10items'=>[1,10,false],'f25items'=>[1,25,false],'g40items_firmada'=>[2,20,true],
 'h30'=>[1,30,false],'i34'=>[1,34,false],'j36'=>[1,36,false],'k38'=>[1,38,false],'l42'=>[1,42,false],
 'm_obslarga'=>[1,5,false,true,true],'n_obscond_largos_many'=>[2,15,false,true,true],'o_desc'=>[1,3,false,false,false,5.0],
];
foreach($cases as $k=>$c){ $d=mk(...$c); $pdf=$svc->generate($d,false)->output(); file_put_contents("/tmp/q986_$k.pdf",$pdf);}
$pdf=$svc->generate(mk(2,3,true),true)->output(); file_put_contents("/tmp/q986_z_external.pdf",$pdf);
echo "ok\n";
