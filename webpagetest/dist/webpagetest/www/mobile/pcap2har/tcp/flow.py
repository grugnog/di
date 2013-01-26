import logging
import tcp
from tcp.direction import Direction


class Flow:
  '''
  Represents TCP traffic across a given socket, ideally between a TCP
  handshake and clean connection termination.

  Members:
  * fwd, rev = tcp.Direction, both sides of the communication stream
  * socket = ((srcip, sport), (dstip, dport)). Used for checking the direction
    of packets. Taken from SYN or first packet.
  * packets = list of tcp.Packet's, all packets in the flow
  * handshake = None or (syn, synack, ack) or False. None while a handshake is
    still being searched for, False when we've given up on finding it.
  '''
  def __init__(self, options):
    self.fwd = Direction(self)
    self.rev = Direction(self)
    self.handshake = None
    self.socket = None
    self.packets = []
    self.options = options
  def add(self, pkt):
    '''
    called for every packet coming in, instead of iterating through a list
    '''
    # make sure packet is in time order
    if len(self.packets): # if we have received packets before...
      if self.packets[-1].ts > pkt.ts: # if this one is out of order...
        # error out
        #raise ValueError("packet added to TCPFlow out of chronological order")
        # BLAZE: Try to just ignore this error
        return
    
    self.packets.append(pkt)
    # look out for handshake
    # add it to the appropriate direction, if we've found or given up on
    # finding handshake
    if self.handshake is not None:
      self.merge_pkt(pkt)
    else: # if handshake is None, we're still looking for a handshake
      if len(self.packets) > 13: # or something like that
        # give up
        logging.warning("TCP handshake detection failed.")
        self.handshake = False
        self.socket = self.packets[0].socket
        self.flush_packets() # merge all stored packets
      # check last three packets
      elif tcp.detect_handshake(self.packets[-3:]):
        # function handles packets < 3 case
        self.handshake = tuple(self.packets[-3:])
        self.socket = self.handshake[0].socket
        self.flush_packets()
  def flush_packets(self):
    '''
    Flush packet buffer by merging all packets into either fwd or rev.
    '''
    for packet in self.packets:
      self.merge_pkt(packet)

  def merge_pkt(self, pkt):
    '''
    Merges the packet into either the forward or reverse stream, depending
    on its direction.
    '''
    if self.samedir(pkt):
      self.fwd.add(pkt)
    else:
      self.rev.add(pkt)
  def finish(self):
    '''
    Notifies the flow that there are no more packets. This finalizes the
    handshake and socket, flushes any built-up packets, and calls finish on
    fwd and rev.
    '''
    # handle the case where no handshake was detected
    if self.handshake is None:
      self.handshake = False
      self.socket = self.packets[0].socket
      self.flush_packets()
    self.fwd.finish()
    self.rev.finish()
  def samedir(self, pkt):
    '''
    returns whether the passed packet is in the same direction as the
    assumed direction of the flow, which is either that of the SYN or the
    first packet. Raises RuntimeError if self.socket is None
    '''
    if not self.socket:
      raise RuntimeError("tcp.Flow.samedir before direction is determined")
    src, dst = pkt.socket
    if self.socket == (src, dst):
      return True
    elif self.socket == (dst, src):
      return False
    else:
      raise ValueError("tcp.Flow.samedir found a packet from the wrong flow")

  def start(self):
    return self.packets[0].ts
