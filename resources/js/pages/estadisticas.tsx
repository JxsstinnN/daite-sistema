import { Button } from '@/components/ui/button';
import { Head, usePage } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { DynamicSelect } from '@/components/dynamic-select';
import { useState } from 'react';
import BarChartGraphic from '@/components/barchartgraphic';
import { useQuery } from '@tanstack/react-query';
import { fetchSingleEntity, fetchDatos } from '@/lib/api';
import PieChartGraphic from '@/components/piechartgraphic';

export default function Estadisticas() {
    const { modules } = usePage().props as unknown as { modules: { valor: string; descripcion: string }[] };
    const [selectedModule, setSelectedModule] = useState<string>('');
    const [selectedInforme, setSelectedInforme] = useState<string>('');

    const { data: detalles, isLoading: loadingDetalles } = useQuery({
      queryKey: ['detalles', selectedInforme],
      queryFn: () => fetchSingleEntity(selectedInforme),
      enabled: !!selectedInforme,
      select: (data) => data[0],
    });

    const { data: datos, isLoading: loadingDatos } = useQuery({
      queryKey: ['datos', selectedInforme],
      queryFn: () => fetchDatos(selectedInforme),
      enabled: !!selectedInforme && !!detalles,
    });

    const chartData = (datos || []).map((item: { x: string; y: string | number }) => ({
      x: item.x?.trim() ?? '',
      y: Number(item.y),
    }));

    return (
        <>
            <Head title="Estadisticas" />

            <div className="bg-[#e6f0f9] p-4 rounded-t-md flex items-center w-full">
                <Button variant="ghost" size="icon" className="bg-blue-600 text-white h-8 w-8">
                    <ArrowLeft className="h-4 w-4" />
                </Button>
                <h2 className="text-xl font-semibold text-[#0066b3] flex-grow text-center">Estadisticas</h2>
            </div>

            <div className="p-4">
                <div className="grid grid-cols-2 gap-4">
                    <DynamicSelect
                        label="Módulo"
                        id="id_modulo"
                        name="module"
                        parametros={{
                            options: modules.map(module => ({
                                value: module.valor,
                                label: module.descripcion
                            }))
                        }}
                        onValueChange={(value) => setSelectedModule(value)}
                        value={selectedModule}
                        withRefresh={false}
                        placeholder="Selecciona una opción"
                    />

                    <DynamicSelect
                        label="Estadistica"
                        id="id_tipo_informe"
                        name="type"
                        isDependent={true}
                        withRefresh={false}
                        dependentOn={{
                            selectId: "id_modulo",
                            valueKey: "value"
                        }}
                        procedure={{
                            name: "p_traer_filtros",
                            params: {
                                valor: (value) => value,
                                renglon: "informes",
                                tipo_filtro: "modulo"
                            }
                        }}
                        onValueChange={(value) => {
                            setSelectedInforme(value);
                            console.log(value);
                        }}
                        value=""
                        placeholder="Selecciona una opción"
                        disabled={!selectedModule}
                    />
                </div>
            </div>


            <div className="p-4">
                {loadingDetalles || loadingDatos ? (
                <div className="flex justify-center items-center h-full">
                    <Loader2 className="animate-spin" />
                </div>
                ) : detalles && detalles.tipo === 'BARRA' && chartData.length > 0 ? (
                <BarChartGraphic data={chartData} title={detalles.informe} />
                ) : detalles && detalles.tipo === 'CIRCULAR' && chartData.length > 0 ? (
                <PieChartGraphic data={chartData} title={detalles.informe} />
                ) : (
                <div style={{ textAlign: 'center', color: '#aaa' }}></div>
                )}
            </div>

        </>
    );
}
