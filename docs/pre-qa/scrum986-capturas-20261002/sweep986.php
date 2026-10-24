<?php
use App\Modules\VentasDiseno\Services\QuotePdfService;
$svc = app(QuotePdfService::class);
// png 1x1 escalado por css a ~42px max => usa png de 40x40 generado
$im=imagecreatetruecolor(40,40); imagefill($im,0,0,imagecolorallocate($im,200,80,80)); ob_start(); imagepng($im); $png=base64_encode(ob_get_clean());
$uri='data:image/png;base64,'.$png;
$cond="1. 50% abono inicial, 50% contra entrega.\n2. Entregas de mercancia solo en Ciudad de Panama (monto mayor a \$500).\n3. Cotizacion valida por 15 dias.\n4. Reserva de productos, unicamente con abono.\n5. No se aceptan devoluciones, pasados 15 dias.\n6. Devoluciones, solamente con factura original y mercancia intacta con su empaque.\n7. Se cobrara gastos de almacenaje a partir del primer mes.";
function mk($parts,$per,$photo,$signed,$cond,$uri,$disc=0){
 $p=[];$n=0;
 for($a=1;$a<=$parts;$a++){$it=[];for($i=1;$i<=$per;$i++){$n++;$it[]=['reference'=>"REF-$n",'description'=>($n%7==0?"Descripcion muy larga ":"Lampara ")."item $n ".($n%7==0?str_repeat("texto largo ",12):""),'photo_url'=>$photo?$uri:null,'quantity'=>2,'effective_unit_price'=>10.5,'discount_percent'=>0,'line_total'=>21];}
 $p[]=['name'=>"Partida $a",'subtotal'=>21*$per,'items'=>$it];}
 $s=21*$per*$parts;
 return ['description'=>'C','master_client'=>['name'=>'Cl'],'sub_client'=>['business_name'=>'Sub','receptor_tipo'=>'01'],'ruc'=>'1','contacts'=>[['name'=>'Maria','phone'=>null,'email'=>null]],'sales_project'=>['name'=>'P'],'folio'=>'T-1','generated_at'=>'2026-10-01','owner'=>['name'=>'D','phone'=>null],'parts'=>$p,'subtotal'=>(float)$s,'discount_totals_amount'=>(float)$disc,'itbms'=>round($s*.07,2),'grand_total'=>round($s*1.07-$disc,2),'observations_preview'=>'Fecha de entrega: Por definir','conditions_text'=>$cond,'document_status'=>$signed?'approved':'draft'];}
@mkdir('/tmp/sw');
foreach([false,true] as $ph) for($n=1;$n<=45;$n++){ $parts=$n>12?2:1; $per=(int)ceil($n/$parts);
 file_put_contents("/tmp/sw/".($ph?'ph':'np')."_".sprintf('%02d',$n).".pdf",$svc->generate(mk($parts,$per,$ph,$n%2==0,$cond,$uri,$n%3==0?3:0),false)->output());}
echo "ok\n";
