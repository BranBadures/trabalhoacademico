<?php

namespace App\Http\Controllers;


use App\Models\Agendamento;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Http\Requests\AgendamentoRequest;
use App\Models\Banca;
use Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\EmAvaliacaoMail;
use App\Mail\DevolucaoMail;
use App\Mail\AprovacaoMail;
use Uspdev\Replicado\Pessoa;

class AgendamentoController extends Controller
{   
    public function index(Request $request)
    {        
        $this->authorize('LOGADO');
        
        $request->validate([
            'busca_data' => 'required_if:filtro_busca,data|dateformat:d/m/Y',
        ]);
        
        $query = Agendamento::join('users', 'users.id', '=', 'agendamentos.user_id')->orderBy('agendamentos.data_da_defesa', 'desc')->select('agendamentos.*'); 
        if($request->busca != ''){
            $query->where(function($query) use($request){
                $query->orWhere('users.name', 'LIKE', "%$request->busca%");
                $query->orWhere('agendamentos.nome_do_orientador', 'LIKE', "%$request->busca%");
                $query->orWhere('agendamentos.titulo', 'LIKE', "%$request->busca%");
            });
        }
        elseif($request->filtro_busca == 'data'){
            $data = Carbon::CreatefromFormat('d/m/Y', "$request->busca_data");
            $query->whereDate('data_da_defesa','=', $data);
        }
        
        $agendamentos = $query->paginate(20);
        
        if ($agendamentos->count() == null and $request->busca != '') {
            $request->session()->flash('alert-danger', 'Não há registros!');
        }
        return view('agendamentos.index')->with('agendamentos',$agendamentos);
    }

    public function create()
    {
        $this->authorize('LOGADO');
        $agendamento = new Agendamento;
        return view('agendamentos.create')->with('agendamento', $agendamento);
    }

    public function store(AgendamentoRequest $request)
    {
        $this->authorize('LOGADO');
        $validated = $request->validated();
        $validated['data_da_defesa'] = $validated['data_da_defesa']." $request->horario";
        $validated['nome_do_orientador'] = Pessoa::dump($validated['numero_usp_do_orientador'])['nompes'];
        $agendamento = Agendamento::create($validated);
        //Salva o orientador na banca
        $banca = new Banca;
        $banca->codpes = $validated['numero_usp_do_orientador'];
        $banca->nome = $validated['nome_do_orientador'];
        $banca->presidente = 'Sim'; 
        $banca->agendamento_id = $agendamento->id;
        $agendamento->bancas()->save($banca);
        return redirect("/agendamentos/$agendamento->id");
    }

    public function show(Agendamento $agendamento)
    {
        //$this->authorize('LOGADO');
        return view('agendamentos.show', compact('agendamento'));
    }

    public function edit(Agendamento $agendamento)
    {
        $this->authorize('OWNER',$agendamento);
        return view('agendamentos.edit')->with('agendamento', $agendamento);
    }

    public function update(AgendamentoRequest $request, Agendamento $agendamento)
    {
        $this->authorize('OWNER',$agendamento);
        $validated = $request->validated();
        $validated['data_da_defesa'] = $validated['data_da_defesa']." $request->horario";
        $validated['nome_do_orientador'] = Pessoa::dump($validated['numero_usp_do_orientador'])['nompes'];
        $agendamento->update($validated);
        return redirect("/agendamentos/$agendamento->id");
    }

    
    public function destroy(Agendamento $agendamento)
    {
        $this->authorize('OWNER',$agendamento);

        $agendamento->bancas()->delete();
        $files = $agendamento->files;
        foreach($files as $file){
            Storage::delete($file->path);
        }
        $agendamento->files()->delete();
        $agendamento->delete();
        return redirect('/agendamentos');
    }

    public function enviar_avaliacao(Agendamento $agendamento){
        $this->authorize('OWNER',$agendamento);

        $agendamento->status = 'Em Avaliação';
        $agendamento->data_enviado_avaliacao = date('Y-m-d');
        $agendamento->update();
        # Mandar email para orientador
        Mail::send(new EmAvaliacaoMail($agendamento));
        return redirect('/agendamentos/'.$agendamento->id);
    }

    public function devolver_avaliacao(Agendamento $agendamento){
        $this->authorize('DOCENTE',$agendamento);
        $agendamento->status = 'Devolvido';
        $agendamento->data_enviado_avaliacao = null;
        $agendamento->data_devolucao = date('Y-m-d');
        $agendamento->update();
        # Mandar email para orientador
        Mail::send(new DevolucaoMail($agendamento));
        return redirect('/agendamentos/'.$agendamento->id);
    }

    public function aprovacao(Agendamento $agendamento, $resultado){
        $this->authorize('DOCENTE',$agendamento);
        if($resultado == 'aprovar'){$agendamento->status = 'Aprovado';}elseif($resultado == 'reprovar'){$agendamento->status = 'Reprovado';}
        $agendamento->data_resultado = date('Y-m-d');
        $agendamento->update();
        Mail::send(new AprovacaoMail($agendamento));
        return redirect('/agendamentos/'.$agendamento->id);
        
    }
}
