import { useMaskito } from "@maskito/react";
import { MaskitoOptions } from "@maskito/core";
import { InputLabel, InputLabelProps } from "@/components/ui/input-label";
import {maskitoNumberOptionsGenerator} from '@maskito/kit';

// Definimos las máscaras
const masks: Record<string, MaskitoOptions> = {
  cedula: {
    mask: [/\d/, /\d/, /\d/, "-", /\d/, /\d/, /\d/, /\d/, /\d/, /\d/, /\d/, "-", /\d/],
  },
  telefono: {
    mask: ["(", /\d/, /\d/, /\d/, ") ", /\d/, /\d/, /\d/, "-", /\d/, /\d/, /\d/, /\d/],
  },
  fecha: {
    mask: [/\d/, /\d/, "/", /\d/, /\d/, "/", /\d/, /\d/, /\d/, /\d/],
  },
  numeros: {
    mask: [/\d/, /\d/, /\d/, /\d/, /\d/, /\d/, /\d/, /\d/],
  },
  dinero: maskitoNumberOptionsGenerator({
    prefix: '',
    thousandSeparator: ',',
    decimalSeparator: '.',
    precision: 2,
  }),
};

interface MaskedInputProps extends Omit<InputLabelProps, "ref"> {
  maskType: keyof typeof masks;
}

const MaskedInput = ({ maskType, ...props }: MaskedInputProps) => {
  const inputRef = useMaskito({ options: masks[maskType] });
  return (
    <InputLabel
      {...props}
      ref={inputRef}
    />
  );
};

export default MaskedInput;
