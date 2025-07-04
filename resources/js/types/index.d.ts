import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy-js';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: Config & { location: string };
    [key: string]: unknown;
}

export interface User {
    id_usuario: number;
    usuario: string;
    email: string;
    pin: string;
    [key: string]: unknown; // This allows for additional properties...
}


interface sidebarItem {
    id_programa: number
    referencia: string
    id_modulo: number
    modulo: string
    tipo_programa: string
    programa: string
    descripcion: string
    icono: string
    orden_menu: number
    generico: boolean
    exclusivo: boolean
    administracion: boolean
    visible: boolean
    aplicacion_movil: boolean
    favorito: number
}

interface Modulo {
    id_modulo: string;
    modulo: string;
    referencia: string;
}

interface SessionData {
    usuario: User;
    modulos: Modulo[];
    programas: {
        registros: { [key: string]: Programa[] };
        procesos: {  [key: string]: Programa[] };
        reportes: {  [key: string]: Programa[] };
        favoritos: { [key: string]: Programa[] };
        genericos: { [key: string]: Programa[] };
    };
}

interface Programa {
    id_programa: string;
    programa: string;
    descripcion: string;
    visible: boolean;
    favorito: boolean;
}